import cv2
import mediapipe as mp
import pygame
from pygame.locals import *
from OpenGL.GL import *
from OpenGL.GLU import *
from collections import deque
import numpy as np
import serial
import time

# Inicializar MediaPipe Hands
mp_hands = mp.solutions.hands

HAND_CONNECTIONS = [
    (0, 1), (1, 2), (2, 3), (3, 4),
    (0, 5), (5, 6), (6, 7), (7, 8),
    (0, 9), (9, 10), (10, 11), (11, 12),
    (0, 13), (13, 14), (14, 15), (15, 16),
    (0, 17), (17, 18), (18, 19), (19, 20)
]

# Índices de los puntos de referencia para cada dedo
FINGER_TIPS = [4, 8, 12, 16, 20]  # Pulgar, índice, medio, anular, meñique
FINGER_PIPS = [2, 6, 10, 14, 18]  # Articulaciones base de los dedos
FINGER_MCP = [1, 5, 9, 13, 17]  # Articulaciones del metacarpo

# Nombres de los dedos
FINGER_NAMES = ["Pulgar", "Índice", "Medio", "Anular", "Meñique"]
FINGER_COMMANDS = ["THUMB_UP", "INDEX_UP", "MIDDLE_UP", "RING_UP", "PINKY_UP"]

# Configuración serial (ajusta el puerto COM según tu sistema)
# Windows: "COM3", Linux: "/dev/ttyUSB0", Mac: "/dev/tty.usbmodem..."
try:
    arduino = serial.Serial('COM3', 9600, timeout=1)
    time.sleep(2)  # Esperar a que Arduino se inicialice
    print("Conectado a Arduino")
except:
    print("Error: No se pudo conectar a Arduino")
    arduino = None

cap = cv2.VideoCapture(0)
cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)

pygame.init()
display = (800, 600)
pygame.display.set_mode(display, DOUBLEBUF | OPENGL)
gluPerspective(45, (display[0] / display[1]), 0.1, 100.0)
glTranslatef(0.0, 0.0, -2.0)
clock = pygame.time.Clock()

# Suavizado y estabilidad
alpha = 0.4
smoothed_landmarks = [(0, 0, 0)] * 21
prev_landmarks_valid = False
last_seen_frame = 0
MAX_MISSING_FRAMES = 30
jump_threshold = 0.5
previous_points = [(0, 0, 0)] * 21

fps_values = deque(maxlen=20)
frame_time_values = deque(maxlen=20)
drop_rate_values = deque(maxlen=20)

# Variables para control de impresión
last_gesture = ""
last_command = ""
gesture_cooldown = 0


def is_finger_extended(tip_idx, pip_idx, mcp_idx, landmarks):
    if len(landmarks) < 21:
        return False

    tip = np.array(landmarks[tip_idx])
    pip = np.array(landmarks[pip_idx])
    mcp = np.array(landmarks[mcp_idx])

    tip_to_pip_dist = np.linalg.norm(tip - pip)
    tip_to_mcp_dist = np.linalg.norm(tip - mcp)

    if tip_idx == 4:  # Pulgar
        return tip[0] > pip[0]
    else:
        return tip_to_mcp_dist > tip_to_pip_dist * 1.2


def detect_gesture(landmarks):
    if len(landmarks) < 21:
        return "Mano no detectada", None

    extended_fingers = []

    for i in range(5):
        tip_idx = FINGER_TIPS[i]
        pip_idx = FINGER_PIPS[i]
        mcp_idx = FINGER_MCP[i]

        if is_finger_extended(tip_idx, pip_idx, mcp_idx, landmarks):
            extended_fingers.append(i)

    # Determinar comando para Arduino
    command = None
    if len(extended_fingers) == 5:
        gesture_text = "¡Todos los dedos levantados!"
        command = "ALL_UP"
    elif len(extended_fingers) == 1:
        finger_name = FINGER_NAMES[extended_fingers[0]]
        gesture_text = f"Solo el dedo {finger_name} levantado"
        command = FINGER_COMMANDS[extended_fingers[0]]
    elif len(extended_fingers) == 0:
        gesture_text = "Mano cerrada (ningún dedo levantado)"
        command = "ALL_DOWN"
    else:
        finger_names = [FINGER_NAMES[i] for i in extended_fingers]
        gesture_text = f"Múltiples dedos: {', '.join(finger_names)}"
        # Crear patrón binario para combinación
        pattern = ''.join(['1' if i in extended_fingers else '0' for i in range(5)])
        command = f"COMBO:{pattern}"

    return gesture_text, command


def send_to_arduino(command):
    if arduino and command and command != last_command:
        arduino.write((command + '\n').encode())
        print(f"Enviado a Arduino: {command}")
        return command
    return last_command


with mp_hands.Hands(
        max_num_hands=1,
        min_detection_confidence=0.7,
        min_tracking_confidence=0.6) as hands:
    frame_count = 0
    start_time = time.time()
    dropped_frames = 0

    while cap.isOpened():
        ret, frame = cap.read()
        frame_start_time = time.time()
        if not ret:
            break

        rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        results = hands.process(rgb_frame)

        updated = False
        new_landmarks = []

        hand_detected = False
        current_points = []
        if results.multi_hand_landmarks:
            hand_detected = True
            last_seen_frame = 0
            for hand_landmarks in results.multi_hand_landmarks:
                for landmark in hand_landmarks.landmark:
                    x_opengl = (landmark.x * 2) - 1
                    y_opengl = 1 - (landmark.y * 2)
                    z_opengl = landmark.z
                    current_points.append((x_opengl, y_opengl, z_opengl))

            for i, (x, y, z) in enumerate(current_points):
                prev_x, prev_y, prev_z = smoothed_landmarks[i]
                dx, dy, dz = abs(x - prev_x), abs(y - prev_y), abs(z - prev_z)
                if dx > jump_threshold or dy > jump_threshold or dz > jump_threshold:
                    x, y, z = prev_x, prev_y, prev_z

                smooth_x = alpha * x + (1 - alpha) * prev_x
                smooth_y = alpha * y + (1 - alpha) * prev_y
                smooth_z = alpha * z + (1 - alpha) * prev_z
                new_landmarks.append((smooth_x, smooth_y, smooth_z))

            smoothed_landmarks = new_landmarks
            previous_points = current_points.copy()
            prev_landmarks_valid = True
            updated = True

            # Detectar gesto y enviar a Arduino
            current_gesture, current_command = detect_gesture(smoothed_landmarks)

            if current_gesture != last_gesture and gesture_cooldown <= 0:
                print(current_gesture)
                last_gesture = current_gesture
                last_command = send_to_arduino(current_command)
                gesture_cooldown = 10

        else:
            last_seen_frame += 1
            if last_seen_frame < MAX_MISSING_FRAMES:
                current_points = smoothed_landmarks
            else:
                current_points = []
                prev_landmarks_valid = False

        if gesture_cooldown > 0:
            gesture_cooldown -= 1

        if not hand_detected:
            dropped_frames += 1

        if last_seen_frame >= MAX_MISSING_FRAMES:
            smoothed_landmarks = [(0, 0, 0)] * 21
            prev_landmarks_valid = False

        glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT)

        if prev_landmarks_valid:
            glPointSize(8)
            glColor3f(0.0, 1.0, 0.0)
            glBegin(GL_POINTS)
            for x, y, z in smoothed_landmarks:
                glVertex3f(x, y, z)
            glEnd()

            glLineWidth(3)
            glColor3f(1.0, 1.0, 0.0)
            glBegin(GL_LINES)
            for start, end in HAND_CONNECTIONS:
                glVertex3f(*smoothed_landmarks[start])
                glVertex3f(*smoothed_landmarks[end])
            glEnd()

        frame_count += 1
        elapsed_time = time.time() - start_time

        if elapsed_time >= 5.0:
            fps = frame_count / elapsed_time
            drop_rate = (dropped_frames / frame_count) * 100
            print(f"FPS: {fps:.2f} | Tiempo por frame: {1000 / fps:.2f} ms | Tracking perdido: {drop_rate:.2f}%")
            frame_count = 0
            dropped_frames = 0
            start_time = time.time()

        pygame.display.flip()
        clock.tick(60)

        for event in pygame.event.get():
            if event.type == QUIT or (event.type == KEYDOWN and event.key == K_q):
                if arduino:
                    arduino.close()
                cap.release()
                cv2.destroyAllWindows()
                pygame.quit()
                exit()


