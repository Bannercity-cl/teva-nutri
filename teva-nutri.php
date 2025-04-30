<?php
/**
 * Plugin Name: TEVA - Pregunta a Nutricionista
 * Description: Plugin para manejar preguntas y login vía AJAX en WordPress.
 * Version: 1.1.0
 * Author: TEVA
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit; // Seguridad
}

class TEVA_Nutricionista {
    private $options;
    private $option_name = 'teva_nutricionista_settings';

    public function __construct() {
        // Inicializar plugin
        add_action('init', array($this, 'init'));
        
        // Añadir acciones AJAX
        add_action('wp_ajax_login_ajax', array($this, 'login_ajax_function'));
        add_action('wp_ajax_nopriv_login_ajax', array($this, 'login_ajax_function'));
        add_action('wp_ajax_question_ajax', array($this, 'question_ajax_function'));
        add_action('wp_ajax_nopriv_question_ajax', array($this, 'question_ajax_function'));
        
        // Registrar shortcode y scripts
        add_shortcode('question_form', array($this, 'question_form_shortcode'));
        add_action('wp_footer', array($this, 'insert_question_script'));
        
        // Añadir menú de administración
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function init() {
        // Cargar opciones
        $this->options = get_option($this->option_name, array(
            'api_url' => '',
            'api_token' => '',
            'user_id' => ''
        ));
    }

    // Agregar página de configuración al menú de administración
    public function add_admin_menu() {
        add_options_page(
            'Configuración TEVA Nutricionista',
            'TEVA Nutricionista',
            'manage_options',
            'teva-nutricionista',
            array($this, 'admin_page')
        );
    }

    // Registrar configuraciones
    public function register_settings() {
        register_setting(
            'teva_nutricionista_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
        
        add_settings_section(
            'teva_nutricionista_section',
            'Configuración de API',
            array($this, 'settings_section_callback'),
            'teva-nutricionista'
        );
        
        add_settings_field(
            'api_url',
            'URL de API Intercom',
            array($this, 'api_url_callback'),
            'teva-nutricionista',
            'teva_nutricionista_section'
        );
        
        add_settings_field(
            'api_token',
            'Token de API Intercom',
            array($this, 'api_token_callback'),
            'teva-nutricionista',
            'teva_nutricionista_section'
        );
        
        add_settings_field(
            'user_id',
            'ID de Usuario Intercom',
            array($this, 'user_id_callback'),
            'teva-nutricionista',
            'teva_nutricionista_section'
        );
    }

    // Funciones de callback para la página de administración
    public function settings_section_callback() {
        echo '<p>Configura los datos de la API de Intercom.</p>';
    }

    public function api_url_callback() {
        $value = isset($this->options['api_url']) ? esc_attr($this->options['api_url']) : '';
        echo '<input type="text" id="api_url" name="' . $this->option_name . '[api_url]" value="' . $value . '" class="regular-text" />';
    }

    public function api_token_callback() {
        $value = isset($this->options['api_token']) ? esc_attr($this->options['api_token']) : '';
        echo '<input type="password" id="api_token" name="' . $this->option_name . '[api_token]" value="' . $value . '" class="regular-text" />';
    }

    public function user_id_callback() {
        $value = isset($this->options['user_id']) ? esc_attr($this->options['user_id']) : '';
        echo '<input type="text" id="user_id" name="' . $this->option_name . '[user_id]" value="' . $value . '" class="regular-text" />';
    }

    // Sanitizar las configuraciones antes de guardarlas
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['api_url'])) {
            $sanitized['api_url'] = esc_url_raw(trim($input['api_url']));
        }
        
        if (isset($input['api_token'])) {
            $sanitized['api_token'] = sanitize_text_field($input['api_token']);
        }
        
        if (isset($input['user_id'])) {
            $sanitized['user_id'] = sanitize_text_field($input['user_id']);
        }
        
        return $sanitized;
    }

    // Página de administración
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?= esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
            <?php
                settings_fields('teva_nutricionista_group');
                do_settings_sections('teva-nutricionista');
                submit_button('Guardar configuración');
            ?>
            </form>
        </div>
        <?php
    }

    // Método para realizar peticiones a la API de Intercom
    private function getRequestIntercom($method, $url, $params) {
        if (empty($this->options['api_url']) || empty($this->options['api_token'])) {
            $this->log_error("ERROR: api_url o api_token no están configurados.");
            return array('error' => true, 'message' => 'Configuración incorrecta');
        }

        try {
            $curl = curl_init();
            
            curl_setopt_array($curl, array(
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $this->options['api_token'],
                    "Content-Type: application/json",
                    "Intercom-Version: 2.10"
                ),
                CURLOPT_POSTFIELDS => $params,
                CURLOPT_URL => $this->options['api_url'] . $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 30
            ));

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($error) {
                $this->log_error("ERROR cURL: $error");
                return array('error' => true, 'message' => $error);
            }

            $decoded = json_decode($response, true);
            
            if (isset($decoded['errors'])) {
                $this->log_error("ERROR en respuesta: " . json_encode($decoded['errors']));
            }

            return $decoded;
        } catch (Exception $e) {
            $this->log_error("EXCEPCIÓN: " . $e->getMessage());
            return array('error' => true, 'message' => 'Exception: ' . $e->getMessage());
        }
    }

    // Método para escribir en el log de errores de WordPress
    private function log_error($message) {
        if (WP_DEBUG === true) {
            error_log($message);
        }
    }

    // Funciones AJAX
    public function login_ajax_function() {
        check_ajax_referer('teva_nutricionista_nonce', 'security');
        
        $response = array();
        $this->log_error("=== INICIO LOGIN AJAX ===");
        
        $email = filter_input(INPUT_POST, 'user', FILTER_SANITIZE_EMAIL);
        if (!is_email($email)) {
            $this->log_error("Email inválido: $email");
            wp_send_json_error(array('message' => 'Email inválido'));
            return;
        }
        
        $query = json_encode(array("query" => array("field" => "email", "operator" => "=", "value" => $email)));
        $this->log_error("Buscando usuario con email: $email");
        
        $user = $this->getRequestIntercom('POST', "/contacts/search", $query);
        
        if (isset($user['error']) && $user['error'] === true) {
            $this->log_error("Error al buscar usuario: " . $user['message']);
            wp_send_json_error(array('message' => 'Error en la autenticación'));
            return;
        }
        
        if (isset($user['total_count']) && $user['total_count'] != 0) {
            $idIntercom = $user["data"][$user['total_count'] - 1]["id"];
            $this->log_error("ID de usuario existente: $idIntercom");
            $response['idIntercom'] = $idIntercom;
        } else {
            $this->log_error("Creando usuario nuevo");
            $query = json_encode(array("role" => "user", "email" => $email));
            $newUser = $this->getRequestIntercom('POST', "/contacts", $query);
            
            if (isset($newUser["id"])) {
                $idIntercom = $newUser["id"];
                $this->log_error("Nuevo usuario creado con ID: $idIntercom");
                $response['idIntercom'] = $idIntercom;
            } else {
                $this->log_error("ERROR al crear usuario: " . json_encode($newUser));
                wp_send_json_error(array('message' => 'No se pudo crear el usuario'));
                return;
            }
        }
        
        // Usar transient para almacenar los datos del usuario temporalmente (más seguro que sessions)
        $session_token = wp_generate_password(32, false);
        set_transient('teva_user_' . $session_token, array(
            'email' => $email,
            'id' => $idIntercom
        ), 3600); // Expira en 1 hora
        
        $response['token'] = $session_token;
        $this->log_error("Token generado: $session_token para: $email con ID: $idIntercom");
        $this->log_error("=== FIN LOGIN AJAX ===");
        
        wp_send_json_success($response);
    }

    public function question_ajax_function() {
        check_ajax_referer('teva_nutricionista_nonce', 'security');
        
        $this->log_error("=== INICIO QUESTION AJAX ===");
        
        $session_token = filter_input(INPUT_POST, 'token', FILTER_SANITIZE_SPECIAL_CHARS);
        $question = filter_input(INPUT_POST, 'question', FILTER_SANITIZE_SPECIAL_CHARS);
        
        // Verificar token y obtener datos de usuario
        $user_data = get_transient('teva_user_' . $session_token);
        
        if (!$user_data) {
            $this->log_error("Token inválido o expirado: $session_token");
            wp_send_json_error(array('message' => 'Sesión expirada o inválida'));
            return;
        }
        
        $id = $user_data['id'];
        $email = $user_data['email'];
        
        $this->log_error("Pregunta de $email: $question");
        
        if (empty($question) || strlen($question) < 5) {
            $this->log_error("Pregunta vacía o demasiado corta");
            wp_send_json_error(array('message' => 'La pregunta es demasiado corta'));
            return;
        }
        
        $query = json_encode(array("from" => array("type" => "user", "id" => $id), "body" => $question));
        $this->log_error("Creando conversación para usuario: $id");
        
        $conversation = $this->getRequestIntercom('POST', "/conversations", $query);
        
        if ($conversation && isset($conversation['conversation_id'])) {
            $this->log_error("Conversación creada con ID: " . $conversation['conversation_id']);
            
            if (!empty($this->options['user_id'])) {
                $data = json_encode(array(
                    "message_type" => "assignment",
                    "type" => "admin",
                    "admin_id" => $this->options['user_id'],
                    "assignee_id" => $this->options['user_id']
                ));
                
                $this->log_error("Asignando conversación al admin: " . $this->options['user_id']);
                $update = $this->getRequestIntercom('POST', "/conversations/" . $conversation['conversation_id'] . "/parts", $data);
                
                if (!$update) {
                    $this->log_error("ERROR al actualizar conversación: " . json_encode($update));
                }
            }
            
            // Eliminar la sesión temporal
            delete_transient('teva_user_' . $session_token);
            $this->log_error("Token eliminado: $session_token");
            
            $this->log_error("=== FIN QUESTION AJAX ===");
            wp_send_json_success(array('message' => 'Pregunta enviada correctamente'));
        } else {
            $this->log_error("ERROR al crear conversación");
            delete_transient('teva_user_' . $session_token);
            wp_send_json_error(array('message' => 'Error al enviar la pregunta'));
        }
    }

    // Shortcode para mostrar el formulario
    public function question_form_shortcode() {
        ob_start();
        ?>
        <div class="teva-nutricionista-container">
            <div id="form-login">
                <input type="email" id="user-email" placeholder="Tu correo" aria-label="Tu correo electrónico">
                <label for="terms-and-conditions">
                    <input type="checkbox" id="terms-and-conditions">
                    Acepto los términos y condiciones
                </label>
                <div class="terms-explanation">
                    Puede revisar detalladamente nuestros <a href="https://tomateloenserio.cl/wp-content/uploads/2025/04/PoliticasPrivacidad_TES-2025.pdf" target="_blank" rel="noopener noreferrer">términos y condiciones de servicio aquí</a> antes de aceptar
                </div>
                <button id="login-intercom" class="teva-button">Iniciar sesión</button>
                <div id="messages" class="teva-messages"></div>
            </div>
            <div id="form-question" style="display: none;">
                <textarea id="question" placeholder="Escribe tu pregunta" aria-label="Tu pregunta para el nutricionista"></textarea>
                <button id="send-question" class="teva-button">Enviar Pregunta</button>
                <button id="cancel-question" class="teva-button teva-button-secondary">Cancelar</button>
            </div>
            <input type="hidden" id="user-session-token" value="">
        </div>
        <?php
        return ob_get_clean();
    }

    // Insertar código JavaScript en la página
    public function insert_question_script() {
        // Solo incluir el script en páginas que tengan el shortcode
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'question_form')) {
            
            // Crear nonce para seguridad AJAX
            $nonce = wp_create_nonce('teva_nutricionista_nonce');
            
            ?>
            <script>
            (function($) {
                $(document).ready(function() {
                    // Validar email
                    function isValidEmail(email) {
                        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
                    }
                    
                    // Mostrar mensaje
                    function showMessage(message, isError = false) {
                        $('#messages').html(message)
                            .removeClass('success error')
                            .addClass(isError ? 'error' : 'success')
                            .show();
                        
                        setTimeout(function() {
                            $('#messages').fadeOut();
                        }, 5000);
                    }
                    
                    // Login
                    $('#login-intercom').click(function(e) {
                        e.preventDefault();
                        const email = $('#user-email').val().trim();
                        const termsAccepted = $('#terms-and-conditions').prop('checked');
                        
                        $('#messages').hide();
                        
                        if (!email) {
                            showMessage("Por favor, ingresa tu correo electrónico.", true);
                            return;
                        }
                        
                        if (!isValidEmail(email)) {
                            showMessage("Por favor, ingresa un correo electrónico válido.", true);
                            return;
                        }
                        
                        if (!termsAccepted) {
                            showMessage("Debes aceptar los términos y condiciones.", true);
                            return;
                        }
                        
                        // Deshabilitar el botón durante la solicitud
                        $('#login-intercom').prop('disabled', true).text('Procesando...');
                        
                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            type: 'POST',
                            data: {
                                action: 'login_ajax',
                                security: '<?php echo $nonce; ?>',
                                user: email
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#form-login').hide();
                                    $('#user-session-token').val(response.data.token);
                                    $('#form-question').show();
                                } else {
                                    showMessage(response.data.message || "Error al iniciar sesión.", true);
                                }
                            },
                            error: function() {
                                showMessage("Error de conexión. Por favor, intenta nuevamente.", true);
                            },
                            complete: function() {
                                $('#login-intercom').prop('disabled', false).text('Iniciar sesión');
                            }
                        });
                    });
                    
                    // Enviar pregunta
                    $('#send-question').click(function(e) {
                        e.preventDefault();
                        const question = $('#question').val().trim();
                        const token = $('#user-session-token').val();
                        
                        if (!question) {
                            showMessage("Por favor, escribe tu pregunta.", true);
                            return;
                        }
                        
                        if (!token) {
                            showMessage("Error de sesión. Por favor, inicia sesión nuevamente.", true);
                            $('#form-question').hide();
                            $('#form-login').show();
                            return;
                        }
                        
                        // Deshabilitar el botón durante la solicitud
                        $('#send-question').prop('disabled', true).text('Enviando...');
                        
                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            type: 'POST',
                            data: {
                                action: 'question_ajax',
                                security: '<?php echo $nonce; ?>',
                                token: token,
                                question: question
                            },
                            success: function(response) {
                                if (response.success) {
                                    // Redirigir a página de agradecimiento
                                    window.location.href = '/gracias';
                                } else {
                                    showMessage(response.data.message || "Error al enviar la pregunta.", true);
                                    $('#send-question').prop('disabled', false).text('Enviar Pregunta');
                                }
                            },
                            error: function() {
                                showMessage("Error de conexión. Por favor, intenta nuevamente.", true);
                                $('#send-question').prop('disabled', false).text('Enviar Pregunta');
                            }
                        });
                    });
                    
                    // Cancelar pregunta
                    $('#cancel-question').click(function(e) {
                        e.preventDefault();
                        $('#form-question').hide();
                        $('#user-session-token').val('');
                        $('#question').val('');
                        $('#form-login').show();
                    });
                });
            })(jQuery);
            </script>
            <style>
                .teva-nutricionista-container {
                    max-width: 600px;
                    margin: 20px auto;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                
                .teva-nutricionista-container input[type="email"],
                .teva-nutricionista-container textarea {
                    width: 100%;
                    padding: 10px;
                    margin: 10px 0;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    font-size: 16px;
                }
                
                .teva-nutricionista-container textarea {
                    min-height: 120px;
                    resize: vertical;
                }
                
                .teva-button {
                    background-color: #4CAF50;
                    color: white;
                    padding: 10px 15px;
                    margin: 10px 5px 10px 0;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 16px;
                    transition: background 0.3s;
                }
                
                .teva-button:hover {
                    background-color: #45a049;
                }
                
                .teva-button-secondary {
                    background-color: #f44336;
                }
                
                .teva-button-secondary:hover {
                    background-color: #d32f2f;
                }
                
                .teva-button:disabled {
                    background-color: #cccccc;
                    cursor: not-allowed;
                }
                
                .teva-messages {
                    padding: 10px;
                    margin: 10px 0;
                    border-radius: 4px;
                    display: none;
                }
                
                .teva-messages.error {
                    background-color: #ffebee;
                    color: #c62828;
                    border: 1px solid #ef9a9a;
                }
                
                .teva-messages.success {
                    background-color: #e8f5e9;
                    color: #2e7d32;
                    border: 1px solid #a5d6a7;
                }
                
                .terms-explanation {
                    font-size: 14px;
                    color: #666;
                    margin: 5px 0 15px;
                }
            </style>
            <?php
        }
    }
}

// Inicializar el plugin
new TEVA_Nutricionista();
