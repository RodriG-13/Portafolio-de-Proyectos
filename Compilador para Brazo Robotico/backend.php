<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

/**
 * BACKEND - API
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $accion = $_POST['action'];
    $codigo = $_POST['code'] ?? '';
    
    try {
        // 1. ANÁLISIS LÉXICO
        $lexer = new AnalizadorLexico("matriz_transiciones.csv");
        $resultadoLex = $lexer->analizar($codigo);

        if ($accion === 'Pila de Errores') {
            echo json_encode(['status' => 'success', 'data' => $resultadoLex['errores'], 'type' => 'errores']);
            exit;
        }

        if (count($resultadoLex['errores']) > 0) {
            echo json_encode([
                'status' => 'success',
                'data' => [],
                'errores_lexicos' => $resultadoLex['errores'],
                'errores_sintacticos' => [],
                'errores_semanticos' => [],
                'bytecode' => '',
                'type' => 'compilacion'
            ]);
            exit;
        }

        // 2. ANÁLISIS SINTÁCTICO
        $parser = new AnalizadorSintactico($resultadoLex['tokens']);
        $resultadoSin = $parser->analizar();

        if ($accion === 'Tabla de Símbolos' || $accion === 'Compilar') {
            
            $erroresSemanticos = [];
            $bytecodeGenerado = '';
            $estadoSerial = "";
            
            // 3. ANÁLISIS SEMÁNTICO (solo si no hay errores sintácticos)
            if (empty($resultadoSin['errores'])) {
                $semantico = new AnalizadorSemantico($resultadoLex['tokens'], $parser->obtenerTablaSimbolos());
                $erroresSemanticos = $semantico->analizar();
                
                // 4. GENERACIÓN DE BYTECODE (El envío al Arduino ahora lo hace JS)
                if ($accion === 'Compilar' && empty($erroresSemanticos)) {
                    $generador = new GeneradorBytecode($resultadoLex['tokens'], $parser->obtenerTablaSimbolos());
                    $bytecodeGenerado = $generador->generar();
                    
                    $estadoSerial = "ÉXITO: Código compilado. El navegador enviará los datos al hardware.";
                }
            }
            
            echo json_encode([
                'status' => 'success', 
                'data' => $resultadoLex['tokens'], 
                'valores' => $parser->obtenerTablaSimbolos(),
                'errores_lexicos' => [],
                'errores_sintacticos' => $resultadoSin['errores'],
                'errores_semanticos' => $erroresSemanticos,
                'bytecode' => $bytecodeGenerado,
                'mensaje_serial' => $estadoSerial, 
                'type' => $accion === 'Tabla de Símbolos' ? 'tabla' : 'compilacion'
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit; 
}


/**
 * GENERADOR DE BYTECODE (INTÉRPRETE Y DESENROLLADO)
 */
class GeneradorBytecode {
    private $tokens;
    private $tablaSimbolos;
    private $bytecode = [];
    private $pos = 0;

    private $mapaHex = [
        'move' => '0x01', 'rotate' => '0x02',
        'up' => '0x10', 'down' => '0x11',
        'low' => '0x20', 'middle' => '0x21', 'high' => '0x22',
        'thumb' => '0x30', 'index_fng' => '0x31', 'middle_fng' => '0x32', 
        'ring_fng' => '0x33', 'little_fng' => '0x34', 'elbow' => '0x35',
        'left' => '0x40', 'right' => '0x41', 'degree' => '0x50'
    ];

    public function __construct($tokens, $tablaSimbolos) {
        $this->tokens = $tokens;
        $this->tablaSimbolos = $tablaSimbolos; 
    }

    public function generar() {
        $this->pos = 0;
        $this->ejecutarBloque(false);
        return implode(' ', $this->bytecode);
    }

    private function ejecutarBloque($esSubBloque = true) {
        while ($this->pos < count($this->tokens)) {
            $token = $this->tokens[$this->pos];
            $id = $token['id'];

            if ($esSubBloque && $id == 3020) { 
                $this->pos++; 
                return;
            }

            if ($id == 1130) { $this->ejecutarMove(); } 
            elseif ($id == 1160) { $this->ejecutarRotate(); } 
            elseif ($id == 1190 || $id == 1200) { $this->ejecutarDeclaracion(); } 
            elseif ($token['tipo'] == 'Identificador') { $this->ejecutarAsignacionOIncremento(); } 
            elseif ($id == 1060) { $this->ejecutarIf(); } 
            elseif ($id == 1040) { $this->ejecutarFor(); } 
            else { $this->pos++; }
        }
    }

    private function saltarBloque() {
        $llavesAbiertas = 1;
        while ($this->pos < count($this->tokens) && $llavesAbiertas > 0) {
            $token = $this->tokens[$this->pos];
            if ($token['id'] == 3010) $llavesAbiertas++; 
            if ($token['id'] == 3020) $llavesAbiertas--; 
            $this->pos++;
        }
    }

    private function ejecutarMove() {
        $this->pos++; 
        $this->bytecode[] = '0x01';
        $idsParte = [1170, 1070, 1120, 1150, 1090]; 
        $tieneParte = false;
        $bufferMov = [];

        while ($this->pos < count($this->tokens) && $this->tokens[$this->pos]['id'] != 3050) {
            $tok = $this->tokens[$this->pos];
            $lex = $tok['lexema'];
            if (isset($this->mapaHex[$lex])) {
                $bufferMov[] = $this->mapaHex[$lex];
            }
            if (in_array($tok['id'], $idsParte)) {
                $tieneParte = true;
            }
            $this->pos++;
        }

        foreach ($bufferMov as $byte) {
            $this->bytecode[] = $byte;
        }

        if (!$tieneParte) {
            $this->bytecode[] = '0x35'; 
        }

        $this->bytecode[] = '0xFF'; 
        $this->pos++; 
    }

    private function ejecutarRotate() {
        $this->pos++; 
        $this->bytecode[] = '0x02'; 
        
        while ($this->pos < count($this->tokens) && $this->tokens[$this->pos]['id'] != 3050) {
            $tok = $this->tokens[$this->pos];
            $lex = $tok['lexema'];
            if (isset($this->mapaHex[$lex])) {
                $this->bytecode[] = $this->mapaHex[$lex];
            } elseif ($tok['tipo'] == 'Número') {
                $this->bytecode[] = sprintf("0x%02X", intval($lex)); 
            } elseif ($tok['tipo'] == 'Identificador' && isset($this->tablaSimbolos[$lex])) {
                $this->bytecode[] = sprintf("0x%02X", intval($this->tablaSimbolos[$lex]['valor']));
            }
            $this->pos++;
        }
        $this->bytecode[] = '0xFF';
        $this->pos++;
    }

    private function ejecutarDeclaracion() {
        $this->pos++; 
        $nombreVar = $this->tokens[$this->pos]['lexema'];
        $this->pos++; 
        if ($this->tokens[$this->pos]['id'] == 2030) { 
            $this->pos++; 
            $this->tablaSimbolos[$nombreVar]['valor'] = $this->obtenerValorToken();
            $this->pos++; 
        }
        $this->pos++; 
    }

    private function ejecutarAsignacionOIncremento() {
        $nombreVar = $this->tokens[$this->pos]['lexema'];
        $this->pos++;
        if ($this->tokens[$this->pos]['id'] == 2030) { 
            $this->pos++;
            $this->tablaSimbolos[$nombreVar]['valor'] = $this->obtenerValorToken();
            $this->pos++;
            if ($this->pos < count($this->tokens) && $this->tokens[$this->pos]['id'] == 3050) $this->pos++; 
        } elseif ($this->tokens[$this->pos]['id'] == 2020) { 
            $this->tablaSimbolos[$nombreVar]['valor']++;
            $this->pos++;
            if ($this->pos < count($this->tokens) && $this->tokens[$this->pos]['id'] == 3050) $this->pos++;
        }
    }

    private function obtenerValorToken() {
        $tok = $this->tokens[$this->pos];
        if ($tok['tipo'] == 'Número') return intval($tok['lexema']);
        if ($tok['lexema'] == 'true') return true;
        if ($tok['lexema'] == 'false') return false;
        if ($tok['tipo'] == 'Identificador') return $this->tablaSimbolos[$tok['lexema']]['valor'];
        return $tok['lexema'];
    }

    private function evaluarExpresion($posInicio, $posFin) {
        $expresion = "return (";
        for ($i = $posInicio; $i < $posFin; $i++) {
            $tok = $this->tokens[$i];
            $lex = $tok['lexema'];
            
            if ($tok['tipo'] == 'Identificador' && $lex != 'true' && $lex != 'false') {
                $val = $this->tablaSimbolos[$lex]['valor'] ?? 0;
                $expresion .= (is_bool($val) ? ($val ? 'true ' : 'false ') : $val . " ");
            } 
            elseif ($tok['tipo'] == 'Palabra Reservada' && !in_array($lex, ['true', 'false'])) {
                $expresion .= "'" . $lex . "' ";
            } else {
                $expresion .= $lex . " ";
            }
        }
        $expresion .= ");";
        
        try {
            return eval($expresion); 
        } catch (Throwable $e) {
            return false; 
        }
    }

    private function ejecutarIf() {
        $this->pos += 2; 
        $posInicioCond = $this->pos;
        while ($this->tokens[$this->pos]['id'] != 3040) $this->pos++; 
        $condicion = $this->evaluarExpresion($posInicioCond, $this->pos);
        $this->pos += 2; 
        
        if ($condicion) {
            $this->ejecutarBloque(true); 
            if ($this->pos < count($this->tokens) && $this->tokens[$this->pos]['id'] == 1030) { 
                $this->pos += 2; 
                $this->saltarBloque(); 
            }
        } else {
            $this->saltarBloque(); 
            if ($this->pos < count($this->tokens) && $this->tokens[$this->pos]['id'] == 1030) { 
                $this->pos += 2; 
                $this->ejecutarBloque(true); 
            }
        }
    }

    private function ejecutarFor() {
        $this->pos += 2; 
        
        if ($this->tokens[$this->pos]['id'] == 1190 || $this->tokens[$this->pos]['id'] == 1200) {
            $this->ejecutarDeclaracion(); 
        } else {
            $this->ejecutarAsignacionOIncremento();
        }
        
        $posInicioCond = $this->pos;
        while ($this->tokens[$this->pos]['id'] != 3050) $this->pos++; 
        $posFinCond = $this->pos;
        $this->pos++; 
        
        $posInicioInc = $this->pos;
        while ($this->tokens[$this->pos]['id'] != 3040) $this->pos++; 
        $this->pos += 2; 
        
        $posInicioBloque = $this->pos;
        
        while (true) {
            if (!$this->evaluarExpresion($posInicioCond, $posFinCond)) break;
            
            $this->pos = $posInicioBloque; 
            $this->ejecutarBloque(true); 
            
            $this->pos = $posInicioInc; 
            $this->ejecutarAsignacionOIncremento(); 
        }
        
        $this->pos = $posInicioBloque;
        $this->saltarBloque();
    }
}

/**
 * ANALIZADOR LÉXICO 
 */
class AnalizadorLexico {
    private $alfabeto = [];
    private $transiciones = [];
    private $estadoInicial = 'q0';

    private $estadoAId = [
        'q6' => 1010, 'q9' => 1020, 'q13' => 1030, 'q16' => 1040,
        'q20' => 1050, 'q22' => 1060, 'q30' => 1070, 'q34' => 1080,
        'q43' => 1090, 'q45' => 1100, 'q51' => 1110, 'q55' => 1120,
        'q58' => 1130, 'q63' => 1140, 'q69' => 1150, 'q74' => 1160,
        'q79' => 1170, 'q81' => 1180,
        'q83' => 1190, 'q91' => 1200,
        'q304' => 2030, 'q305' => 2040, 'q306' => 2060, 'q308' => 2050,
        'q309' => 2010, 'q310' => 2020,
        'q316' => 2070, 'q318' => 2080,
        'q401' => 4010,
        'q330' => 3050, 'q332' => 3010, 'q333' => 3020, 'q334' => 3030, 'q335' => 3040,
        'q200' => 'Num', 'q82' => 'Id'
    ];

    public function __construct($archivoCsv) {
        $this->cargarMatriz($archivoCsv);
    }

    private function cargarMatriz($archivoCsv) {
        if (($gestor = fopen($archivoCsv, "r")) !== FALSE) {
            $encabezados = fgetcsv($gestor, 1000, ",");
            $this->alfabeto = array_slice($encabezados, 1);
            while (($fila = fgetcsv($gestor, 1000, ",")) !== FALSE) {
                if (empty($fila[0])) continue;
                $estadoActual = trim($fila[0]);
                foreach ($this->alfabeto as $i => $simbolo) {
                    $estadoSiguiente = isset($fila[$i + 1]) ? trim($fila[$i + 1]) : "";
                    if ($estadoSiguiente !== "") {
                        $this->transiciones[$estadoActual][$simbolo] = $estadoSiguiente;
                    }
                }
            }
            fclose($gestor);
        }
    }

    private function mapearCaracter($caracter) {
        if (in_array($caracter, $this->alfabeto)) return $caracter;
        if (ctype_digit($caracter) && in_array('0-9', $this->alfabeto)) return '0-9';
        if (ctype_alpha($caracter)) {
            if (in_array('a-z', $this->alfabeto) && ctype_lower($caracter)) return 'a-z';
            if (in_array('A-Z', $this->alfabeto) && ctype_upper($caracter)) return 'A-Z';
            if (in_array('a-z', $this->alfabeto)) return 'a-z';
        }
        $comodines = ['Otro', 'Otros', 'Cualquier', 'Cualquiera', 'Error'];
        foreach ($comodines as $col) {
            if (in_array($col, $this->alfabeto)) return $col;
        }
        return $caracter; 
    }

    private function obtenerTipoToken($estado) {
        $reservadas = ['q6','q9','q13','q16','q20','q22','q30','q34','q43','q45','q51','q55','q58','q63','q69','q74','q79','q81','q83','q91'];
        $operadores = ['q304','q305','q306','q308','q309','q310','q316','q318'];
        $delimitadores = ['q330','q332','q333','q334','q335'];

        if (in_array($estado, $reservadas)) return "Palabra Reservada";
        if (in_array($estado, $operadores)) return "Operador";
        if (in_array($estado, $delimitadores)) return "Delimitador";
        if ($estado === 'q401') return "Caracteres";
        if ($estado === 'q200') return "Número";
        if ($estado === 'q82') return "Identificador";
        
        return "Desconocido";
    }

    public function analizar($codigoFuente) {
        $tokensValidados = [];
        $pilaErrores = [];
        $longitud = strlen($codigoFuente);
        $i = 0;

        while ($i < $longitud) {
            $charActual = $codigoFuente[$i];
            if (ctype_space($charActual)) { $i++; continue; }

            $estadoActual = $this->estadoInicial;
            $bufferToken = "";
            $recorrido = [$estadoActual];
            
            while ($i < $longitud) {
                $char = $codigoFuente[$i];
                if (ctype_space($char) && $bufferToken !== "") break;

                $simbolo = $this->mapearCaracter($char);

                if (isset($this->transiciones[$estadoActual][$simbolo])) {
                    $estadoActual = $this->transiciones[$estadoActual][$simbolo];
                    $recorrido[] = $estadoActual;
                    $bufferToken .= $char;
                    $i++;
                    if ($estadoActual === 'q901' || $estadoActual === 'q902') break;
                } else {
                    if (isset($this->estadoAId[$estadoActual])) break; 
                    else {
                        $bufferToken .= $char;
                        $i++;
                        break;
                    }
                }
            }

            $rutaStr = implode(" -> ", $recorrido);

            if ($estadoActual === 'q901') {
                $pilaErrores[] = "( Error carácter inválido, '$bufferToken', $rutaStr )";
                break;
            } elseif ($estadoActual === 'q902') {
                $pilaErrores[] = "( Error token inválido, '$bufferToken', $rutaStr )";
                break;
            } elseif (isset($this->estadoAId[$estadoActual])) {
                $tipoToken = $this->obtenerTipoToken($estadoActual);
                $idToken = $this->estadoAId[$estadoActual];

                if ($bufferToken == 'true' || $bufferToken == 'false') {
                    $tipoToken = 'Palabra Reservada';
                    $idToken = 1200; 
                }

                $tokensValidados[] = [
                    "lexema" => $bufferToken, 
                    "id" => $idToken,
                    "tipo" => $tipoToken
                ];
            } else {
                $pilaErrores[] = "( Error léxico por transición no definida, '$bufferToken', $rutaStr )";
                break;
            }
        }
        return ['tokens' => $tokensValidados, 'errores' => $pilaErrores];
    }
}

/**
 * ANALIZADOR SINTÁCTICO 
 */
class AnalizadorSintactico {
    private $tokens;
    private $posicion;
    private $maxPosicion;
    private $tablaSimbolos;

    public function __construct($tokens) {
        $this->tokens = $tokens;
        $this->posicion = 0;
        $this->maxPosicion = 0;
        $this->tablaSimbolos = [];
    }

    public function analizar() {
        if (empty($this->tokens)) {
            return ['exito' => true, 'errores' => []];
        }

        if ($this->programa() && $this->posicion === count($this->tokens)) {
            return ['exito' => true, 'errores' => []];
        }

        $tokenError = $this->tokens[$this->maxPosicion]['lexema'] ?? 'Fin de Archivo';
        $tipoError = $this->tokens[$this->maxPosicion]['tipo'] ?? '';
        return [
            'exito' => false, 
            'errores' => ["Error de Sintaxis cerca del token '$tokenError' ($tipoError). La estructura no coincide con la gramática permitida."]
        ];
    }

    public function obtenerTablaSimbolos() {
        return $this->tablaSimbolos;
    }

    private function match($idBuscado) {
        if ($this->posicion < count($this->tokens) && $this->tokens[$this->posicion]['id'] == $idBuscado) {
            $this->posicion++;
            if ($this->posicion > $this->maxPosicion) {
                $this->maxPosicion = $this->posicion;
            }
            return true;
        }
        return false;
    }

    private function programa() { return $this->lista_sentencias(); }
    private function lista_sentencias() {
        $pos = $this->posicion;
        if ($this->sentencia()) { $this->lista_sentencias(); return true; }
        $this->posicion = $pos;
        return true;
    }

    private function sentencia() {
        $pos = $this->posicion;
        if ($this->comando_movimiento()) return true; $this->posicion = $pos;
        if ($this->comando_movimiento_codo()) return true; $this->posicion = $pos;
        if ($this->comando_rotacion()) return true; $this->posicion = $pos;
        if ($this->sentencia_if_else()) return true; $this->posicion = $pos;
        if ($this->sentencia_if()) return true; $this->posicion = $pos;
        if ($this->sentencia_for()) return true; $this->posicion = $pos;
        if ($this->declaracion() && $this->match(3050)) return true; $this->posicion = $pos;
        if ($this->asignacion() && $this->match(3050)) return true; $this->posicion = $pos;
        if ($this->incremento() && $this->match(3050)) return true; $this->posicion = $pos;
        return false;
    }

    private function declaracion() {
        $pos = $this->posicion;
        if ($this->match(1190) && $this->match('Id')) {
            $this->tablaSimbolos[$this->tokens[$this->posicion - 1]['lexema']] = ['tipo' => 'int', 'valor' => null, 'declarado' => true];
            return true;
        }
        $this->posicion = $pos;
        if ($this->match(1200) && $this->match('Id')) {
            $this->tablaSimbolos[$this->tokens[$this->posicion - 1]['lexema']] = ['tipo' => 'boolean', 'valor' => null, 'declarado' => true];
            return true;
        }
        $this->posicion = $pos;
        return false;
    }

    private function comando_movimiento() {
        $pos = $this->posicion;
        if ($this->match(1130) && $this->direccion() && $this->magnitud() && $this->parte() && $this->match(3050)) return true;
        $this->posicion = $pos; return false;
    }

    private function comando_movimiento_codo() {
        $pos = $this->posicion;
        if ($this->match(1130) && $this->direccion() && $this->magnitud() && $this->match(3050)) return true;
        $this->posicion = $pos; return false;
    }

    private function comando_rotacion() {
        $pos = $this->posicion;
        if ($this->match(1160) && $this->dir_rotacion() && $this->match(1010) && $this->match(3030) && $this->match('Num') && $this->match(3040) && $this->match(3050)) return true;
        $this->posicion = $pos; return false;
    }

    private function direccion() {
        $pos = $this->posicion;
        if ($this->match(1180)) return true; $this->posicion = $pos;
        if ($this->match(1020)) return true; $this->posicion = $pos;
        return false;
    }

    private function magnitud() {
        $pos = $this->posicion;
        if ($this->match(1100)) return true; $this->posicion = $pos;
        if ($this->match(1110)) return true; $this->posicion = $pos;
        if ($this->match(1050)) return true; $this->posicion = $pos;
        return false;
    }

    private function parte() {
        $pos = $this->posicion;
        if ($this->match(1170)) return true; $this->posicion = $pos;
        if ($this->match(1070)) return true; $this->posicion = $pos;
        if ($this->match(1120)) return true; $this->posicion = $pos;
        if ($this->match(1150)) return true; $this->posicion = $pos;
        if ($this->match(1090)) return true; $this->posicion = $pos;
        return false;
    }

    private function dir_rotacion() {
        $pos = $this->posicion;
        if ($this->match(1080)) return true; $this->posicion = $pos;
        if ($this->match(1140)) return true; $this->posicion = $pos;
        return false;
    }

    private function sentencia_if_else() {
        $pos = $this->posicion;
        if ($this->match(1060) && $this->match(3030) && $this->expresion() && $this->match(3040) && $this->bloque() && $this->match(1030) && $this->bloque()) return true;
        $this->posicion = $pos; return false;
    }

    private function sentencia_if() {
        $pos = $this->posicion;
        if ($this->match(1060) && $this->match(3030) && $this->expresion() && $this->match(3040) && $this->bloque()) return true;
        $this->posicion = $pos; return false;
    }

    private function bloque() {
        $pos = $this->posicion;
        if ($this->match(3010) && $this->lista_sentencias() && $this->match(3020)) return true;
        $this->posicion = $pos;
        if ($this->match(3010) && $this->match(3020)) return true;
        $this->posicion = $pos; return false;
    }

    private function sentencia_for() {
        $pos = $this->posicion;
        if ($this->match(1040) && $this->match(3030) && $this->declaracion() && $this->match(3050) && $this->expresion() && $this->match(3050) && $this->incremento() && $this->match(3040) && $this->bloque()) return true;
        $this->posicion = $pos;
        if ($this->match(1040) && $this->match(3030) && $this->asignacion() && $this->match(3050) && $this->expresion() && $this->match(3050) && $this->incremento() && $this->match(3040) && $this->bloque()) return true;
        $this->posicion = $pos; return false;
    }

    private function asignacion() {
        $pos = $this->posicion;
        if ($this->tokens[$this->posicion]['id'] == 1190) {
            $this->match(1190);
            $nombreVar = $this->tokens[$this->posicion]['lexema'];
            $this->match('Id');
            if ($this->match(2030) && $this->primario()) {
                $this->tablaSimbolos[$nombreVar] = ['tipo' => 'int', 'valor' => $this->obtenerValorPrimario(), 'declarado' => true];
                return true;
            }
        }
        $this->posicion = $pos;
        if ($this->tokens[$this->posicion]['id'] == 1200) {
            $this->match(1200);
            $nombreVar = $this->tokens[$this->posicion]['lexema'];
            $this->match('Id');
            if ($this->match(2030) && $this->primario()) {
                $this->tablaSimbolos[$nombreVar] = ['tipo' => 'boolean', 'valor' => $this->obtenerValorPrimario(), 'declarado' => true];
                return true;
            }
        }
        $this->posicion = $pos; return false;
    }

    private function obtenerValorPrimario() {
        $token = $this->tokens[$this->posicion - 1];
        if ($token['tipo'] == 'Número') return intval($token['lexema']);
        if ($token['lexema'] == 'true') return true;
        return $token['lexema']; 
    }

    private function incremento() {
        $pos = $this->posicion;
        if ($this->match('Id') && $this->match(2020)) return true;
        $this->posicion = $pos; return false;
    }

    private function expresion() {
        $pos = $this->posicion;
        if ($this->expresion_relacional() && $this->operador_logico() && $this->expresion()) return true;
        $this->posicion = $pos;
        if ($this->expresion_relacional()) return true;
        $this->posicion = $pos; return false;
    }

    private function expresion_relacional() {
        $pos = $this->posicion;
        if ($this->primario() && $this->operador_relacional() && $this->primario()) return true;
        $this->posicion = $pos;
        if ($this->primario()) return true;
        $this->posicion = $pos; return false;
    }

    private function primario() {
        $pos = $this->posicion;
        if ($this->match('Id')) return true; $this->posicion = $pos;
        if ($this->match('Num')) return true; $this->posicion = $pos;
        if ($this->estado_token()) return true; $this->posicion = $pos;
        return false;
    }

    private function estado_token() {
        $pos = $this->posicion;
        foreach ([1170, 1070, 1120, 1150, 1090, 1180, 1020, 1100, 1110, 1050, 1200] as $id) {
            if ($this->match($id)) return true;
            $this->posicion = $pos;
        }
        return false;
    }

    private function operador_relacional() {
        $pos = $this->posicion;
        if ($this->match(2040) || $this->match(2060) || $this->match(2050)) return true;
        $this->posicion = $pos; return false;
    }

    private function operador_logico() {
        $pos = $this->posicion;
        if ($this->match(2070) || $this->match(2080)) return true;
        $this->posicion = $pos; return false;
    }
}

/**
 * ANALIZADOR SEMÁNTICO
 */
class AnalizadorSemantico {
    private $tokens;
    private $tablaSimbolos;
    private $errores;

    public function __construct($tokens, $tablaSimbolos) {
        $this->tokens = $tokens;
        $this->tablaSimbolos = $tablaSimbolos;
        $this->errores = [];
    }

    public function analizar() {
        $this->recolectarDeclaraciones();
        $this->validarExpresiones();
        return $this->errores;
    }

    private function recolectarDeclaraciones() {
        $i = 0;
        $variablesDeclaradas = []; 
        while ($i < count($this->tokens)) {
            $token = $this->tokens[$i];
            
            if ($token['id'] == 1190 || $token['id'] == 1200) {
                $tipoStr = $token['id'] == 1190 ? 'int' : 'boolean';
                if ($i + 1 < count($this->tokens) && $this->tokens[$i + 1]['tipo'] == 'Identificador') {
                    $nombreVar = $this->tokens[$i + 1]['lexema'];
                    if (isset($variablesDeclaradas[$nombreVar])) {
                        $this->errores[] = "Error Semántico: La variable '$nombreVar' ya ha sido declarada.";
                    }
                    $variablesDeclaradas[$nombreVar] = true;
                    if ($i + 2 < count($this->tokens) && $this->tokens[$i + 2]['id'] == 2030 && $i + 3 < count($this->tokens)) {
                        $this->validarAsignacionTipo($tipoStr, $nombreVar, $this->tokens[$i + 3]);
                    }
                }
            }
            if ($token['id'] == 1010 && $i + 2 < count($this->tokens) && $this->tokens[$i + 1]['id'] == 3030 && $this->tokens[$i + 2]['tipo'] == 'Número') {
                $valor = intval($this->tokens[$i + 2]['lexema']);
                if ($valor > 180) {
                    $this->errores[] = "Error Semántico: El valor en degree($valor) excede el límite máximo de 180 grados.";
                }
            }
            $i++;
        }
    }

    private function validarAsignacionTipo($tipoEsperado, $nombreVar, $valorToken) {
        $valor = $valorToken['lexema'];
        $tipoValor = $valorToken['tipo'];
        
        if ($tipoValor == 'Identificador') {
            if (isset($this->tablaSimbolos[$valor])) {
                $tipoReal = $this->tablaSimbolos[$valor]['tipo'];
                if ($tipoReal != $tipoEsperado) $this->errores[] = "Error Semántico: No se puede asignar '$valor' ($tipoReal) a '$nombreVar' ($tipoEsperado).";
            } else {
                $this->errores[] = "Error Semántico: La variable '$valor' no ha sido declarada.";
            }
            return;
        }
        
        if ($tipoValor == 'Palabra Reservada') {
            if ($tipoEsperado == 'int' && in_array($valor, ['true'])) $this->errores[] = "Error Semántico: No se puede asignar boolean a int.";
            if ($tipoEsperado == 'boolean' && !in_array($valor, ['true']) && !in_array($valorToken['id'], [1200])) $this->errores[] = "Error Semántico: No se puede asignar '$valor' a boolean.";
            return;
        }
        
        if ($tipoValor == 'Número' && $tipoEsperado == 'boolean') {
            $this->errores[] = "Error Semántico: No se puede asignar número a boolean.";
        }
    }

    private function validarExpresiones() {
        $i = 0;
        while ($i < count($this->tokens)) {
            $token = $this->tokens[$i];
            if ($i + 2 < count($this->tokens)) {
                $opRel = $this->tokens[$i + 1];
                if (in_array($opRel['id'], [2040, 2050, 2060])) {
                    $this->validarComparacionTipos($token, $this->tokens[$i + 2], $opRel);
                    $i += 2;
                }
            }
            if ($token['tipo'] == 'Identificador' && $token['lexema'] != 'true' && $token['lexema'] != 'false') {
                if (!isset($this->tablaSimbolos[$token['lexema']])) {
                    $this->errores[] = "Error Semántico: La variable '{$token['lexema']}' no ha sido declarada.";
                }
            }
            $i++;
        }
    }

    private function validarComparacionTipos($izq, $der, $op) {
        $tipoIzq = $this->obtenerTipoReal($izq);
        $tipoDer = $this->obtenerTipoReal($der);
        if ($tipoIzq === null || $tipoDer === null) return;
        
        if (($tipoIzq == 'boolean' && $tipoDer == 'int') || ($tipoIzq == 'int' && $tipoDer == 'boolean')) {
            $this->errores[] = "Error Semántico: No se puede comparar boolean con int.";
        }
        if (($tipoIzq == 'estado' && $tipoDer == 'int') || ($tipoIzq == 'int' && $tipoDer == 'estado')) {
            $this->errores[] = "Error Semántico: No se puede comparar estado del sistema con número.";
        }
    }

    private function obtenerTipoReal($token) {
        if ($token['tipo'] == 'Identificador') {
            if ($token['lexema'] == 'true' || $token['lexema'] == 'false') return 'boolean';
            if (isset($this->tablaSimbolos[$token['lexema']])) return $this->tablaSimbolos[$token['lexema']]['tipo'];
            return null;
        }
        if ($token['tipo'] == 'Número') return 'int';
        if ($token['tipo'] == 'Palabra Reservada') {
            if ($token['lexema'] == 'true' || $token['lexema'] == 'false') return 'boolean';
            return 'estado';
        }
        return 'estado';
    }
}