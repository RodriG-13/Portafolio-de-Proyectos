import cv2
import mediapipe as mp
import pygame
from pygame.locals import *
from OpenGL.GL import *
from OpenGL.GLU import *
import time
import matplotlib.pyplot as plt
from collections import deque
import threading
import numpy as np

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
jump_threshold = 0.5  # cambio brusco
previous_points = [(0, 0, 0)] * 21  # Inicializar previous_points

fps_values = deque(maxlen=20)
frame_time_values = deque(maxlen=20)
drop_rate_values = deque(maxlen=20)

# Variables para control de impresión
last_gesture = ""
gesture_cooldown = 0


def is_finger_extended(tip_idx, pip_idx, mcp_idx, landmarks):
    """
    Determina si un dedo está extendido comparando la distancia entre
    la punta y la articulación MCP (metacarpo).
    """
    if len(landmarks) < 21:
        return False

    tip = np.array(landmarks[tip_idx])
    pip = np.array(landmarks[pip_idx])
    mcp = np.array(landmarks[mcp_idx])

    # Calcular distancias
    tip_to_pip_dist = np.linalg.norm(tip - pip)
    tip_to_mcp_dist = np.linalg.norm(tip - mcp)

    # Si la distancia de la punta al MCP es significativamente mayor
    # que la distancia de la punta al PIP, el dedo está extendido
    if tip_idx == 4:  # Pulgar - lógica diferente
        # Para el pulgar, comparamos la posición X
        return tip[0] > pip[0]  # Pulgar extendido si está hacia la derecha
    else:
        return tip_to_mcp_dist > tip_to_pip_dist * 1.2


def detect_gesture(landmarks):
    """
    Detecta qué dedos están levantados y retorna el mensaje correspondiente.
    """
    if len(landmarks) < 21:
        return "Mano no detectada"

    extended_fingers = []

    # Verificar cada dedo
    for i in range(5):
        tip_idx = FINGER_TIPS[i]
        pip_idx = FINGER_PIPS[i]
        mcp_idx = FINGER_MCP[i]

        if is_finger_extended(tip_idx, pip_idx, mcp_idx, landmarks):
            extended_fingers.append(i)

    # Determinar el gesto basado en los dedos extendidos
    if len(extended_fingers) == 5:
        return "¡Todos los dedos levantados!"
    elif len(extended_fingers) == 1:
        finger_name = FINGER_NAMES[extended_fingers[0]]
        return f"Solo el dedo {finger_name} levantado"
    elif len(extended_fingers) == 0:
        return "Mano cerrada (ningún dedo levantado)"
    else:
        # Si hay múltiples dedos pero no todos
        finger_names = [FINGER_NAMES[i] for i in extended_fingers]
        return f"Múltiples dedos: {', '.join(finger_names)}"


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
        new_landmarks = []  # Inicializar new_landmarks

        # Detección de mano
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

            # Procesar suavizado solo cuando hay detección
            for i, (x, y, z) in enumerate(current_points):
                # Saltos bruscos
                prev_x, prev_y, prev_z = smoothed_landmarks[i]
                dx, dy, dz = abs(x - prev_x), abs(y - prev_y), abs(z - prev_z)
                if dx > jump_threshold or dy > jump_threshold or dz > jump_threshold:
                    x, y, z = prev_x, prev_y, prev_z  # ignoramos salto

                # Suavizado
                smooth_x = alpha * x + (1 - alpha) * prev_x
                smooth_y = alpha * y + (1 - alpha) * prev_y
                smooth_z = alpha * z + (1 - alpha) * prev_z
                new_landmarks.append((smooth_x, smooth_y, smooth_z))

            smoothed_landmarks = new_landmarks
            previous_points = current_points.copy()  # Actualizar previous_points
            prev_landmarks_valid = True
            updated = True

            # Detectar gesto actual
            current_gesture = detect_gesture(smoothed_landmarks)

            # Imprimir solo si el gesto cambió y ha pasado el cooldown
            if current_gesture != last_gesture and gesture_cooldown <= 0:
                print(current_gesture)
                last_gesture = current_gesture
                gesture_cooldown = 10  # Cooldown de 10 frames

        else:
            last_seen_frame += 1
            if last_seen_frame < MAX_MISSING_FRAMES:
                # Usar los puntos suavizados anteriores si no hay detección reciente
                current_points = smoothed_landmarks
            else:
                current_points = []
                prev_landmarks_valid = False

        # Reducir cooldown
        if gesture_cooldown > 0:
            gesture_cooldown -= 1

        # Contar cuadros sin detección
        if not hand_detected:
            dropped_frames += 1

        # Si no se ve la mano por mucho tiempo, limpiar
        if last_seen_frame >= MAX_MISSING_FRAMES:
            smoothed_landmarks = [(0, 0, 0)] * 21
            prev_landmarks_valid = False

        # Dibujar en OpenGL
        glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT)

        if prev_landmarks_valid:
            # Puntos
            glPointSize(8)
            glColor3f(0.0, 1.0, 0.0)
            glBegin(GL_POINTS)
            for x, y, z in smoothed_landmarks:
                glVertex3f(x, y, z)
            glEnd()

            # Conexiones
            glLineWidth(3)
            glColor3f(1.0, 1.0, 0.0)
            glBegin(GL_LINES)
            for start, end in HAND_CONNECTIONS:
                glVertex3f(*smoothed_landmarks[start])
                glVertex3f(*smoothed_landmarks[end])
            glEnd()

        # Contadores de rendimiento
        frame_count += 1
        elapsed_time = time.time() - start_time

        if elapsed_time >= 5.0:  # muestra cada 5 segundos
            fps = frame_count / elapsed_time
            drop_rate = (dropped_frames / frame_count) * 100
            print(
                f"FPS promedio: {fps:.2f} | Tiempo por frame: {1000 / fps:.2f} ms | Tracking perdido: {drop_rate:.2f}%")
            # Reiniciar contadores
            frame_count = 0
            dropped_frames = 0
            start_time = time.time()

        pygame.display.flip()
        clock.tick(60)

        for event in pygame.event.get():
            if event.type == QUIT or (event.type == KEYDOWN and event.key == K_q):
                cap.release()
                cv2.destroyAllWindows()
                pygame.quit()
                exit()


def plot_metrics():
    plt.ion()
    fig, ax = plt.subplots(3, 1, figsize=(8, 6))
    titles = ['FPS', 'Tiempo por frame (ms)', 'Frames sin detección (%)']

    while True:
        if len(fps_values) == 0:
            continue

        for i, (data, title) in enumerate(zip(
                [fps_values, frame_time_values, drop_rate_values], titles
        )):
            ax[i].clear()
            ax[i].plot(list(data), marker='o')
            ax[i].set_title(title)
            ax[i].set_ylim(0, max(35 if i == 0 else 100, max(data) + 5))
            ax[i].grid(True)

        plt.tight_layout()
        plt.pause(0.1)


cap.release()
cv2.destroyAllWindows()
pygame.quit()