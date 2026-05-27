// Pines para los LEDs (cada uno representa un dedo)
const int thumbLed = 2;     // Pulgar
const int indexLed = 3;     // Índice
const int middleLed = 4;    // Medio
const int ringLed = 5;      // Anular
const int pinkyLed = 6;     // Meñique

void setup() {
  // Inicializar pines de LEDs como salida
  pinMode(thumbLed, OUTPUT);
  pinMode(indexLed, OUTPUT);
  pinMode(middleLed, OUTPUT);
  pinMode(ringLed, OUTPUT);
  pinMode(pinkyLed, OUTPUT);
  
  // Inicializar comunicación serial
  Serial.begin(9600);
  Serial.println("Sistema de mano robótica - Listo");
}

void loop() {
  // Leer comandos desde Python
  if (Serial.available() > 0) {
    String command = Serial.readStringUntil('\n');
    command.trim(); // Eliminar espacios y saltos de línea
    
    // Apagar todos los LEDs primero
    resetAllLeds();
    
    // Procesar comando
    if (command == "ALL_UP") {
      // Todos los dedos levantados - encender todos los LEDs
      setAllLeds(HIGH);
      Serial.println("Comando: TODOS los dedos levantados");
      
    } else if (command == "ALL_DOWN") {
      // Mano cerrada - todos apagados
      Serial.println("Comando: MANO CERRADA");
      
    } else if (command == "THUMB_UP") {
      digitalWrite(thumbLed, HIGH);
      Serial.println("Comando: Solo PULGAR levantado");
      
    } else if (command == "INDEX_UP") {
      digitalWrite(indexLed, HIGH);
      Serial.println("Comando: Solo INDICE levantado");
      
    } else if (command == "MIDDLE_UP") {
      digitalWrite(middleLed, HIGH);
      Serial.println("Comando: Solo MEDIO levantado");
      
    } else if (command == "RING_UP") {
      digitalWrite(ringLed, HIGH);
      Serial.println("Comando: Solo ANULAR levantado");
      
    } else if (command == "PINKY_UP") {
      digitalWrite(pinkyLed, HIGH);
      Serial.println("Comando: Solo MEÑIQUE levantado");
      
    } else if (command.startsWith("COMBO:")) {
      // Para combinaciones personalizadas
      handleCombo(command);
      
    } else {
      Serial.println("Comando desconocido: " + command);
    }
  }
}

void resetAllLeds() {
  digitalWrite(thumbLed, LOW);
  digitalWrite(indexLed, LOW);
  digitalWrite(middleLed, LOW);
  digitalWrite(ringLed, LOW);
  digitalWrite(pinkyLed, LOW);
}

void setAllLeds(int state) {
  digitalWrite(thumbLed, state);
  digitalWrite(indexLed, state);
  digitalWrite(middleLed, state);
  digitalWrite(ringLed, state);
  digitalWrite(pinkyLed, state);
}

void handleCombo(String command) {
  // Ejemplo: "COMBO:10101" donde 1=encendido, 0=apagado
  // Posiciones: Pulgar, Índice, Medio, Anular, Meñique
  if (command.length() >= 11) { // "COMBO:xxxxx" = 11 caracteres
    String pattern = command.substring(6, 11);
    
    digitalWrite(thumbLed, pattern[0] == '1' ? HIGH : LOW);
    digitalWrite(indexLed, pattern[1] == '1' ? HIGH : LOW);
    digitalWrite(middleLed, pattern[2] == '1' ? HIGH : LOW);
    digitalWrite(ringLed, pattern[3] == '1' ? HIGH : LOW);
    digitalWrite(pinkyLed, pattern[4] == '1' ? HIGH : LOW);
    
    Serial.println("Comando COMBO: " + pattern);
  }
}
