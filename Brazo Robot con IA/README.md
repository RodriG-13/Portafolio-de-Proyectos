Brazo Robot con IA - Teleoperacion Gestual

Sistema de Control Robotico mediante Seguimiento de Mano Humana en Tiempo Real



Brazo Robot con IA es un proyecto de robotica y vision artificial desarrollado en Python que permite teleoperar un brazo robotico antropomorfico de multiples grados de libertad mediante la deteccion y seguimiento de la mano humana en tiempo real utilizando una camara RGB convencional.



El sistema utiliza MediaPipe Hands para el reconocimiento de landmarks de la mano y PyOpenGL para la visualizacion tridimensional interactiva, traduciendo los gestos y posiciones de la mano del operador en comandos de control para servomotores a traves de comunicacion serial con Arduino.



Caracteristicas principales



* Deteccion de hasta 21 puntos clave de la mano en tiempo real.
* Reconocimiento de gestos individuales y combinaciones de dedos.
* Renderizado 3D mediante OpenGL de la mano detectada.
* Control de 5 servomotores (4 MG995 + 1 MG95) replicando movimientos.
* Comunicacion serial bidireccional entre Python y Arduino.
* Suavizado de movimiento mediante filtros exponenciales.
* Sistema anti-saltos y filtrado de datos erraticos.
* Monitorizacion de rendimiento (FPS, latencia, tracking perdido).
* Estructura mecanica impresa en 3D disenada en Tinkercad.
* Arquitectura modular y escalable.
* Compatible con Windows, Linux y macOS.



Potencial aplicacion en:



* Teleoperacion y robotica asistencial
* Protesis inteligentes y rehabilitacion
* Educacion en robotica y automatizacion
* Interfaces hombre-maquina (HMI)
* Investigacion en control gestual
* Telecirugia y aplicaciones medicas
* Automatizacion industrial con control intuitivo





