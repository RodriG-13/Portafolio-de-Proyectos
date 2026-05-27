Lenguaje de Control Robótico y Compilador

Sistema de Compilación y Control para Brazo Robótico mediante Lenguaje de Dominio Específico



Lenguaje de Control Robótico y Compilador es un proyecto integral que combina un lenguaje de programación de dominio específico (DSL) con su propio compilador para controlar un brazo robótico antropomórfico mediante gestos o código escrito. El sistema incluye un analizador léxico basado en autómatas finitos deterministas (AFD), un analizador sintáctico recursivo descendente, un analizador semántico y un generador de bytecode que se transmite vía USB al hardware del robot.



El compilador está implementado en PHP con una interfaz web en HTML/CSS/JS que utiliza CodeMirror como editor y Web Serial API para la comunicación directa con el brazo robótico a través del puerto USB.



Características principales



* Análisis léxico mediante matriz de transiciones de estados (AFD) cargada desde CSV.
* Detección y clasificación de tokens: palabras reservadas, identificadores, números, operadores y delimitadores.
* Análisis sintáctico recursivo descendente que valida la gramática del lenguaje.
* Análisis semántico con verificación de tipos, declaraciones de variables y validación de rangos.
* Generación de bytecode hexadecimal para transmisión directa al hardware.
* Editor de código integrado con resaltado de sintaxis (CodeMirror).
* Comunicación serial vía Web Serial API (USB) con tasa de baudios de 115200.
* Tabla de símbolos en tiempo real con valores y tipos de variables.
* Consola de salida con mensajes de compilación y errores.
* Sistema de archivos integrado (nuevo, abrir, guardar archivos .rob).
* Ayuda interactiva con documentación del lenguaje y ejemplos.

