<?php
/**
 * Plugin Name: Planning Danse En Mouvance
 * Description: Affiche le planning hebdomadaire des cours de danse depuis Google Sheets
 * Version: 1.5
 * Author: Benga
 */

// Empêcher l'accès direct au fichier
if (!defined('ABSPATH')) exit;

// Classe principale du plugin
class PlanningDansePlugin {
    private $google_sheet_id;
    private $google_api_key;
    private $timeSlots = []; // Sera rempli dynamiquement

    // Au début de votre classe, ajoutez une méthode utilitaire :
    private function get_redirect_uri() {
        $redirect_uri = admin_url('admin-ajax.php') . '?action=google_auth_callback';
        error_log('Redirect URI: ' . $redirect_uri);
        return $redirect_uri;
    }

    public function __construct() {
        // Initialisation des hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_shortcode('planning_danse', array($this, 'display_planning'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        
        add_action('wp_ajax_submit_trial_booking', array($this, 'handle_trial_booking'));
        add_action('wp_ajax_nopriv_submit_trial_booking', array($this, 'handle_trial_booking'));
        add_action('wp_ajax_test_notification_email', array($this, 'test_notification_email'));

        // Génère les créneaux horaires à partir des réglages
        $this->timeSlots = $this->generate_time_slots();
        
        // Initialisation du texte par défaut
        if (!get_option('planning_no_spectacle_text')) {
            update_option('planning_no_spectacle_text', 'Cours non concerné par le spectacle');
        }
        
        // Initialiser les catégories par défaut si nécessaire
        if (!get_option('planning_course_categories')) {
            update_option('planning_course_categories', [
                'modern-jazz' => [
                    'name' => 'Modern Jazz',
                    'bg' => '#dd4b5c',
                    'text' => '#ffffff'
                ],
                'classique' => [
                    'name' => 'Classique',
                    'bg' => '#ffa431',
                    'text' => '#ffffff'
                ],
                'contemporain' => [
                    'name' => 'Contemporain',
                    'bg' => '#2c9ec3',
                    'text' => '#ffffff'
                ],
                'barre' => [
                    'name' => 'Barre',
                    'bg' => '#ae3770',
                    'text' => '#ffffff'
                ],
                'eveil' => [
                    'name' => 'Eveil',
                    'bg' => '#2fc275',
                    'text' => '#ffffff'
                ],
                'initiation' => [
                    'name' => 'Initiation',
                    'bg' => '#2fc275',
                    'text' => '#ffffff'
                ],
                'strech' => [
                    'name' => 'Strech',
                    'bg' => '#ae3770',
                    'text' => '#ffffff'
                ],
                'scene' => [
                    'name' => 'Scène',
                    'bg' => '#142636',
                    'text' => '#ffffff'
                ],
                'training' => [
                    'name' => 'Training',
                    'bg' => '#ffb366',
                    'text' => '#ffffff'
                ],
                'creation' => [
                    'name' => 'Création',
                    'bg' => '#142636',
                    'text' => '#ffffff'
                ]
                // ... autres catégories existantes
            ]);
        }
        
        // Initialiser les couleurs du ruban si nécessaire
        if (!get_option('planning_ribbon_colors')) {
            update_option('planning_ribbon_colors', [
                'background' => '#EC365B',
                'text' => '#ffffff',
                'corners' => '#BE2D4A'
            ]);
        }
        
        
        // Initialiser les professeurs par défaut si nécessaire
        if (get_option('planning_teachers') === false) { // Important : vérifier avec === false
            update_option('planning_teachers', []);
        }
            
        add_action('wp_head', array($this, 'add_responsive_meta'));
        add_action('wp_enqueue_scripts', array($this, 'generate_dynamic_css'), 11);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_media_uploader'));
        // Hooks pour Google OAuth
        add_action('wp_ajax_google_auth_callback', array($this, 'handle_google_auth'));
        add_action('wp_ajax_disconnect_google', array($this, 'handle_google_disconnect'));
        add_action('wp_ajax_test_google_connection', array($this, 'handle_google_test'));
        add_action('wp_ajax_nopriv_google_auth_callback', array($this, 'handle_google_auth'));
        add_action('wp_ajax_google_auth_callback', array($this, 'handle_google_auth'));

    }

    /**
     * Génère dynamiquement les créneaux horaires en fonction des options de l'admin.
     * @return array La liste des créneaux horaires (ex: ['10:00', '10:15', ...])
     */
    private function generate_time_slots() {
        $start_time_str = get_option('planning_start_time', '10:00');
        $end_time_str = get_option('planning_end_time', '22:15');

        try {
            $start = new DateTime($start_time_str);
            $end = new DateTime($end_time_str);
            // On s'assure que l'heure de fin est bien après l'heure de début
            if ($start >= $end) {
                return [];
            }
            
            $interval = new DateInterval('PT15M'); // Intervalle de 15 minutes
            // On ajoute 1 seconde à la fin pour s'assurer que le dernier créneau est inclus
            $period = new DatePeriod($start, $interval, $end->modify('+1 second')); 

            $slots = [];
            foreach ($period as $date) {
                $slots[] = $date->format('H:i');
            }
            return $slots;
        } catch (Exception $e) {
            // En cas de format d'heure invalide, retourner un tableau vide
            return [];
        }
    }
    
    public function enqueue_scripts() {
    // ...
    
    // Récupérer les catégories et leurs couleurs depuis les paramètres d'administration
    $categories = get_option('planning_course_categories', []);
    
    // Passer les catégories à JavaScript
    wp_localize_script('planning-booking', 'planningCategories', $categories);
    
    wp_localize_script('planning-booking', 'planningSettings', array(
    'noSpectacleText' => get_option('planning_no_spectacle_text')
));
}

    public function handle_google_test() {
        check_ajax_referer('google-test', 'nonce');
    
        $access_token = $this->get_google_access_token();
        if (!$access_token) {
            wp_send_json_error('Impossible d\'obtenir un token d\'accès valide');
            return;
        }
    
        $sheet_id = get_option('booking_sheet_id');
        if (!$sheet_id) {
            wp_send_json_error('ID de la feuille Google non configuré');
            return;
        }
    
        // Test de lecture de la feuille
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/A1:A1";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token
            )
        ));
    
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
            return;
        }
    
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['error'])) {
            wp_send_json_error($body['error']['message']);
            return;
        }
    
        wp_send_json_success('Connexion réussie');
    }
    
    public function handle_google_disconnect() {
        check_ajax_referer('google-disconnect', 'nonce');
    
        delete_option('google_access_token');
        delete_option('google_refresh_token');
        delete_option('google_token_expires');
    
        wp_send_json_success();
    }
    
    // Nouvelle méthode pour ajouter les éléments de réservation
    public function add_booking_elements() {
        remove_action('wp_footer', array($this, 'add_booking_elements'));
        ?>
        <div class="booking-cart">
            <div class="cart-header">
                <h3>Cours sélectionnés</h3>
                <button class="close-cart">&times;</button>
            </div>
            <div class="cart-items"></div>
            <div class="cart-footer">
                <span class="cart-total">0/3 cours sélectionnés</span> 
                <button class="validate-booking" disabled>Valider</button>

            </div>
        </div>
        <div class="booking-cart-toggle">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="9" cy="21" r="1"></circle>
                <circle cx="20" cy="21" r="1"></circle>
                <path d="M6 2L3 6v14c0 1.1.9 2 2 2h14a2 2 0 0 0 2-2V6l-3-4H6zM3.8 6h16.4M16 10a4 4 0 1 1-8 0"/>
            </svg>
        </div>
    
        <div id="booking-form-modal" class="modal">
            <div class="modal-content">
                <h3>Réserver vos cours d'essai</h3>
                <div class="selected-courses"></div>
                <form id="trial-booking-form">
                    <?php
                    $fields = get_option('booking_form_fields', []);
                    foreach ($fields as $field) {
                        echo '<div class="form-field">';
                        echo '<label>' . esc_html($field['label']) . 
                             ($field['required'] ? ' *' : '') . '</label>';
                        
                        if ($field['type'] === 'textarea') {
                            echo '<textarea name="' . esc_attr($field['label']) . '"' . 
                                 ($field['required'] ? ' required' : '') . '></textarea>';
                        } else {
                            echo '<input type="' . esc_attr($field['type']) . '" 
                                         name="' . esc_attr($field['label']) . '"' . 
                                 ($field['required'] ? ' required' : '') . '>';
                        }
                        echo '</div>';
                    }
                    ?>
                    <button type="submit">Envoyer</button>
                </form>
            </div>
        </div>
        <?php
    }

    public function generate_cart_item_styles() {
        $categories = get_option('planning_course_categories', []);
        
        $css = '';
        foreach ($categories as $slug => $category) {
            $css .= ".cart-item-{$slug}::before { background-color: {$category['bg']}; }\n";
            $css .= ".cart-item-{$slug} { color: {$category['text']}; }\n";
        }
        
        wp_add_inline_style('planning-danse-styles', $css);
    }
    
    private function prepare_client_email($form_data, $courses) {
        $template = get_option('booking_client_email_template');
        if (empty($template)) {
            return false;
        }
    
        // Préparer la liste des cours
        $cours_list = "";
        foreach ($courses as $course) {
            // Extraire le label
            $label = '';
            if (preg_match('/label="([^"]+)"/', $course['title'], $matches)) {
                $label = $matches[1];
            } else {
                // Fallback si pas de label
                $lines = explode("\n", $course['title']);
                $clean_title = array_filter($lines, function($line) {
                    return !preg_match('/(à|\d{2}:\d{2}|complet|no spectacle|label=)/i', $line);
                });
                $label = trim(implode(' ', $clean_title));
            }
    
            $cours_list .= "* " . $label . "\n";
            $cours_list .= "   * le " . mb_strtolower($course['day']) . "\n";
            $cours_list .= "   * " . $course['time'] . "\n";
            if (!empty($course['teacher'])) {
                $cours_list .= "   * avec " . $course['teacher'] . "\n";
            }
            $cours_list .= "\n"; // Ligne vide entre chaque cours
        }
    
        // Remplacer les variables dans le template
        $message = str_replace(
            [
                '{prenom}',
                '{nom}',
                '{liste_cours}',
                '{date}'
            ],
            [
                $form_data['Prénom'] ?? '',
                $form_data['Nom'] ?? '',
                $cours_list,
                date('d/m/Y')
            ],
            $template
        );
    
        // Convertir le HTML en texte brut tout en préservant la mise en forme
        $message = wp_strip_all_tags($message);
        
        return $message;
    }
    
    public function handle_trial_booking() {
    if (!isset($_POST['courses']) || !isset($_POST['form'])) {
        wp_send_json_error('Données manquantes');
        return;
    }

    check_ajax_referer('planning_booking_nonce', 'nonce');
    
    $courses = json_decode(stripslashes($_POST['courses']), true);
    $form_data = json_decode(stripslashes($_POST['form']), true);
    
    if (!$courses || !$form_data) {
        wp_send_json_error('Données invalides');
        return;
    }

    $success = true;
    $errors = [];

    foreach ($courses as $course) {
        // Extraire le label officiel du cours
        $label = '';
        if (preg_match('/label="([^"]+)"/', $course['title'], $matches)) {
            $label = $matches[1];
        } else {
            $lines = explode("\n", $course['title']);
            $clean_title = array_filter($lines, function($line) {
                return !preg_match('/(à|\d{2}:\d{2}|complet|no spectacle|label=)/i', $line);
            });
            $label = trim(implode(' ', $clean_title));
        }

        // Récupérer le téléphone
        $phone = '';
        $phone_field_names = ['Téléphone', 'Télephone', 'telephone', 'Tel', 'tel', 'TEL', 'Phone', 'phone'];
        foreach ($phone_field_names as $field) {
            if (isset($form_data[$field])) {
                $phone = $form_data[$field];
                break;
            }
        }

        $row_data = array(
            '', // CODE
            $form_data['Nom'] ?? '', 
            $form_data['Prénom'] ?? '',
            '', // ETAT
            $label,
            '', // DISPO
            '', // ATT
            $form_data['Date de naissance'] ?? '',
            '', // AGE 30/09
            $form_data['email'] ?? $form_data['Email'] ?? $form_data['E-mail'] ?? '',
            $phone,
            '', // HISTORIQUE
            '', // REMARQUE
            '', // ACTION
            '', // JOUR
            '', // SOURCE
            date('Y-m-d'), // DATE
            '', // DATE ESSAI
            '' // BLACK LIST
        );

        $result = $this->append_to_google_sheet($row_data);
        
        if (is_wp_error($result)) {
            $success = false;
            $errors[] = "Erreur pour le cours '$label'";
        }
    }
    
        // Si au moins une ligne a été ajoutée avec succès
        if ($success) {
            $admin_message = "Nouvelle réservation de cours d'essai\n\n";
            $admin_message .= "Cours sélectionnés :\n";
            foreach ($courses as $course) {
                $admin_message .= "- {$course['title']} le {$course['day']} de {$course['time']}\n";
                if (!empty($course['teacher'])) {
                    $admin_message .= "  Prof: {$course['teacher']}\n";
                }
                $admin_message .= "\n";
            }
            
            $admin_message .= "\nInformations du client :\n";
            foreach ($form_data as $field => $value) {
                $admin_message .= "{$field} : {$value}\n";
            }
    
            // Email pour le client
           $client_message = $this->prepare_client_email($form_data, $courses);
            if (!$client_message) {
                $client_message = "Bonjour " . ($form_data['Prénom'] ?? '') . ",\n\n";
                $client_message .= "Nous avons bien reçu votre demande de cours d'essai pour :\n\n";
                foreach ($courses as $course) {
                    $client_message .= "- {$course['title']} le {$course['day']} {$course['time']}\n";
                    if (!empty($course['teacher'])) {
                        $client_message .= "  Prof: {$course['teacher']}\n";
                    }
                    $client_message .= "\n";
                }
                $client_message .= "Nous reviendrons vers vous rapidement pour confirmer votre réservation.\n\n";
                $client_message .= "À bientôt,\n";
                $client_message .= "L'équipe En Mouvance";
            }
    
            $notification_email = get_option('booking_notification_email');
            if (!$notification_email) {
                $notification_email = get_option('admin_email');
            }
    
            $headers = array('Content-Type: text/plain; charset=UTF-8');
            
            // Envoi à l'admin
            $admin_sent = wp_mail(
                $notification_email,
                'Nouvelle réservation de cours d\'essai',
                $admin_message,
                $headers
            );
    
            // Envoi au client
            $client_sent = wp_mail(
                $form_data['email'] ?? $form_data['Email'] ?? $form_data['E-mail'] ?? '',
                'Confirmation de votre demande de cours d\'essai - En Mouvance',
                $client_message,
                $headers
            );
    
            if ($admin_sent && $client_sent) {
                wp_send_json_success('Réservation envoyée avec succès');
            } else {
                wp_send_json_error('Erreur lors de l\'envoi des notifications');
            }
        } else {
            wp_send_json_error('Erreur lors de l\'ajout des données');
        }
    }

    private function append_to_google_sheet($row_data) {
        error_log('=== TENTATIVE ENVOI GOOGLE SHEETS ===');
        error_log('Sheet ID: ' . substr($sheet_id, 0, 5) . '...');
        error_log('URL: ' . $url);
        error_log('Données à envoyer: ' . print_r($row_data, true));
    $sheet_id = get_option('booking_sheet_id');
    $access_token = $this->get_google_access_token();

    if (empty($sheet_id) || empty($access_token)) {
        return new WP_Error('config_error', 'Configuration Google Sheets manquante');
    }

    // Préparation des données
    $values = array($row_data);
    $body = array(
        'values' => $values,
        'majorDimension' => 'ROWS'
    );

    // Construction de la requête
    $url = sprintf(
        'https://sheets.googleapis.com/v4/spreadsheets/%s/values/A:S:append?valueInputOption=RAW',
        $sheet_id
    );

    $response = wp_remote_post($url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode($body),
        'method' => 'POST',
        'timeout' => 30,
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    if ($response_code !== 200) {
        $error_message = isset($response_body['error']['message']) 
            ? $response_body['error']['message'] 
            : 'Erreur inconnue lors de l\'ajout des données';
        return new WP_Error('google_sheets_error', $error_message);
    }

    return $response_body;
}
    
    private function get_google_access_token() {
        $access_token = get_option('google_access_token');
        $token_expires = get_option('google_token_expires');
    
        // Si le token est expiré ou va expirer dans moins de 5 minutes
        if (!$access_token || !$token_expires || $token_expires <= (time() + 300)) {
            $access_token = $this->refresh_google_token();
        }
    
        return $access_token;
    }
    
    public function add_responsive_meta() {
    ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <?php
    }
    
    public function enqueue_media_uploader() {
    wp_enqueue_media();
    }

    //generate filter menu
    public function generate_filter_menu() {
    $categories = get_option('planning_course_categories');
    $teachers = get_option('planning_teachers', []); // Récupération des professeurs
    ob_start();
    ?>
    <div class="planning-filters">
        <div class="days-filter">
            <?php
            $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
            foreach ($days as $day) {
                $dayLower = strtolower($day);
                ?>
                <button type="button" class="day-toggle active" data-day="<?php echo $dayLower; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 6L9 17l-5-5"/>
                    </svg>
                    <?php echo $day; ?>
                </button>
                <?php
            }
            ?>
        </div>
        <div class="filter-group">
            <select id="ageFilter" class="category-filter">
                <option value="all">Tous les âges</option>
                <option value="enfant">Enfant</option>
                <option value="ado">Ado</option>
                <option value="adulte">Adulte</option>
            </select>
            <select id="categoryFilter" class="category-filter">
                <option value="all">Tous les cours</option>
                <?php foreach ($categories as $slug => $category): ?>
                    <option value="<?php echo esc_attr($slug); ?>">
                        <?php echo esc_html($category['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select id="availabilityFilter" class="category-filter">
                <option value="all">Toutes les disponibilités</option>
                <option value="available">Places disponibles</option>
                <option value="full">Complet</option>
            </select>

            <select id="teacherFilter" class="category-filter">
                <option value="all">Tous les professeurs</option>
                <?php foreach ($teachers as $teacher): ?>
                    <option value="<?php echo esc_attr($teacher['firstname']); ?>">
                        <?php echo esc_html($teacher['firstname'] . ' ' . $teacher['lastname']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const ageFilter = document.getElementById('ageFilter');
        const categoryFilter = document.getElementById('categoryFilter');
        const availabilityFilter = document.getElementById('availabilityFilter');
        const teacherFilter = document.getElementById('teacherFilter');
        const planningBody = document.querySelector('.planning-body .planning-table');

        function applyFilters() {
            const selectedAge = ageFilter.value;
            const selectedCategory = categoryFilter.value;
            const selectedAvailability = availabilityFilter.value;
            const selectedTeacher = teacherFilter.value;
            const courseCells = planningBody.getElementsByClassName('course');

            Array.from(courseCells).forEach(cell => {
                // Vérification de l'âge
                let shouldShowAge = selectedAge === 'all';
                if (!shouldShowAge) {
                    const courseContent = cell.textContent.toLowerCase();
                    if (selectedAge === 'enfant' && courseContent.includes('enfant')) {
                        shouldShowAge = true;
                    } else if (selectedAge === 'ado' && courseContent.includes('ado')) {
                        shouldShowAge = true;
                    } else if (selectedAge === 'adulte' && courseContent.includes('adulte')) {
                        shouldShowAge = true;
                    }
                }
                
                // Vérification de la catégorie
                let shouldShowCategory = selectedCategory === 'all' || cell.classList.contains(selectedCategory);
                
                // Vérification de la disponibilité
                let shouldShowAvailability = selectedAvailability === 'all' || 
                    (selectedAvailability === 'available' && !cell.querySelector('.ribbon')) ||
                    (selectedAvailability === 'full' && cell.querySelector('.ribbon'));
                
                // Vérification du professeur
                let shouldShowTeacher = selectedTeacher === 'all';
                if (!shouldShowTeacher) {
                    // Chercher le prénom du professeur dans le tooltip
                    const teacherName = cell.querySelector('.teacher-tooltip-name');
                    if (teacherName) {
                        shouldShowTeacher = teacherName.textContent.toLowerCase()
                            .includes(selectedTeacher.toLowerCase());
                    }
                }

                // Appliquer les filtres combinés
                if (shouldShowCategory && shouldShowAvailability && shouldShowTeacher && shouldShowAge) {
                    cell.style.opacity = '1';
                    cell.style.visibility = 'visible';
                } else {
                    cell.style.opacity = '0.1';
                    cell.style.visibility = 'visible';
                }
            });

            // Maintenir les cellules vides visibles
            const emptyCells = planningBody.getElementsByClassName('empty-cell');
            Array.from(emptyCells).forEach(cell => {
                cell.style.visibility = 'visible';
                cell.style.display = 'table-cell';
            });
        }

        // Écouteurs d'événements pour tous les filtres
        ageFilter.addEventListener('change', applyFilters);
        categoryFilter.addEventListener('change', applyFilters);
        availabilityFilter.addEventListener('change', applyFilters);
        teacherFilter.addEventListener('change', applyFilters);
        
        // Synchronisation du scroll horizontal
        const planningHeaders = document.querySelector('.planning-headers');
        
        if (planningBody && planningHeaders) {
            const planningBodyContainer = document.querySelector('.planning-body');
            planningBodyContainer.addEventListener('scroll', function() {
                planningHeaders.scrollLeft = this.scrollLeft;
            });

            planningHeaders.addEventListener('scroll', function() {
                planningBodyContainer.scrollLeft = this.scrollLeft;
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

    // Ajout du menu d'administration
    private function init_default_colors() {
        if (!get_option('planning_danse_colors')) {
            $default_colors = [
                'modern-jazz' => '#DD4B5C',
                'classique' => '#ffa431',
                'contemporain' => '#2c9ec3',
                'barre' => '#ae3770',
                'eveil' => '#2fc275',
                'stretch' => '#ae3770',
                'scene' => '#142636',
                'training' => '#ffb366',
                'creation' => '#142636',
                'initiation' => '#2fc275'
            ];
            update_option('planning_danse_colors', $default_colors);
        }
    }

    public function add_admin_menu() {
        // Une seule page principale maintenant
        add_menu_page(
            'Planning Danse',
            'Planning Danse',
            'manage_options',
            'planning-danse-settings',
            array($this, 'settings_page'),
            'dashicons-calendar-alt'
        );
    }
    
    public function register_settings() {
        // Settings pour Réglages Généraux
        register_setting('planning-danse-general', 'planning_google_sheet_id');
        register_setting('planning-danse-general', 'planning_google_api_key');
        register_setting('planning-danse-general', 'planning_start_time');
        register_setting('planning-danse-general', 'planning_end_time');
        register_setting('planning-danse-general', 'planning_teacher_display_style');
        
        // Settings pour l'interface
        register_setting('planning-danse-interface', 'planning_danse_interface_colors', array(
            'type' => 'array',
            'default' => [
                'background' => '#1a1a1a',
                'empty_cell' => '#232323',
                'header_bg' => '#2a2a2a',
                'time_cell_bg' => '#2a2a2a',
                'time_cell_text' => '#888888'
            ]
        ));
        
        register_setting('planning-danse-settings', 'booking_client_email_template', [
            'sanitize_callback' => 'wp_kses_post' // Permet le HTML dans le template
        ]);
        register_setting('planning-danse-ribbon', 'planning_ribbon_colors');
        register_setting('planning-danse-nospectacle', 'planning_no_spectacle_text');
        
        // Settings pour les réservations
        register_setting('planning-danse-settings', 'booking_period_start');
        register_setting('planning-danse-settings', 'booking_period_end');
        register_setting('planning-danse-settings', 'booking_notification_email');
        register_setting('planning-danse-settings', 'booking_form_fields', array(
            'sanitize_callback' => function($value) {
                return is_array($value) ? $value : array();
            }
        ));
        // Ajouter les settings pour Google OAuth2
        register_setting('planning-danse-google', 'google_client_id');
        register_setting('planning-danse-google', 'google_client_secret');
        register_setting('planning-danse-google', 'google_refresh_token');
        register_setting('planning-danse-google', 'google_access_token');
        register_setting('planning-danse-google', 'google_token_expires');
        register_setting('planning-danse-google', 'booking_sheet_id');
    }

    public function settings_page() {
    // Récupérer l'onglet actif
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
    
    // Définir les onglets disponibles
    $tabs = array(
        'general' => 'Réglages Généraux',
        'categories' => 'Catégories',
        'colors' => 'Apparence',
        'teachers' => 'Professeurs',
        'booking' => 'Réservations',
        'google' => 'Google API'
    );
    ?>
    <div class="wrap planning-settings-page">
        <h1>Réglages du Planning</h1>

        <!-- En-tête des onglets -->
        <nav class="nav-tab-wrapper">
            <?php foreach ($tabs as $tab_key => $tab_name) : ?>
                <a href="?page=planning-danse-settings&tab=<?php echo $tab_key; ?>" 
                   class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                    <?php echo $tab_name; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="tab-content">
            <?php
            // Afficher le contenu de l'onglet actif
            switch ($current_tab) {
                case 'general':
                    $this->display_general_settings();
                    break;
                case 'categories':
                    $this->display_categories_settings();
                    break;
                case 'colors':
                    $this->display_colors_settings();
                    break;
                case 'teachers':
                    $this->display_teachers_settings();
                    break;
                case 'booking':
                    $this->display_booking_settings();
                    break;
                case 'google':
                    $this->display_google_settings();
                    break;    
            }
            ?>
        </div>
    </div>

    <style>
    .planning-settings-page {
        max-width: 1200px;
        margin: 20px auto;
    }

    .nav-tab-wrapper {
        margin-bottom: 20px;
        border-bottom: 1px solid #ccc;
    }

    .nav-tab {
        font-size: 14px;
        padding: 10px 20px;
        margin-right: 5px;
        border-radius: 4px 4px 0 0;
        border: 1px solid #ccc;
        background: #f8f8f8;
        color: #555;
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .nav-tab:hover {
        background: #fff;
        color: #000;
    }

    .nav-tab-active {
        background: #fff;
        border-bottom-color: #fff;
        color: #000;
    }

    .tab-content {
        background: #fff;
        padding: 20px;
        border: 1px solid #ccc;
        border-top: none;
        min-height: 400px;
    }

    /* Style pour les cartes de configuration */
    .settings-card {
        background: #fff;
        padding: 20px;
        margin-bottom: 20px;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .settings-card h3 {
        margin-top: 0;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }
    </style>
    <?php
}

    private function display_general_settings() {
        ?>
        <div class="settings-card">
            <h3>Configuration API Google Sheets</h3>
            <form method="post" action="options.php">
                <?php
                settings_fields('planning-danse-general');
                do_settings_sections('planning-danse-general');
                ?>
                <table class="form-table">
                    <tr>
                        <th>Google Sheet ID</th>
                        <td>
                            <input type="text" name="planning_google_sheet_id" 
                                   value="<?php echo esc_attr(get_option('planning_google_sheet_id')); ?>" 
                                   class="regular-text">
                            <p class="description">L'ID se trouve dans l'URL du Google Sheet : docs.google.com/spreadsheets/d/<strong>ID-ICI</strong>/edit</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Google API Key</th>
                        <td>
                            <input type="text" name="planning_google_api_key" 
                                   value="<?php echo esc_attr(get_option('planning_google_api_key')); ?>" 
                                   class="regular-text">
                            <p class="description">Clé API à créer dans la <a href="https://console.cloud.google.com/" target="_blank">Console Google Cloud</a></p>
                        </td>
                    </tr>
                     <tr>
                        <th>Heure de début du planning</th>
                        <td>
                            <input type="time" name="planning_start_time" 
                                   value="<?php echo esc_attr(get_option('planning_start_time', '10:00')); ?>">
                            <p class="description">L'heure à laquelle le planning commence.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Heure de fin du planning</th>
                        <td>
                            <input type="time" name="planning_end_time" 
                                   value="<?php echo esc_attr(get_option('planning_end_time', '22:15')); ?>">
                            <p class="description">L'heure à laquelle le planning se termine (le dernier créneau affiché).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Affichage du professeur</th>
                        <td>
                            <select name="planning_teacher_display_style">
                                <option value="photo" <?php selected(get_option('planning_teacher_display_style', 'photo'), 'photo'); ?>>Photo seule</option>
                                <option value="firstname" <?php selected(get_option('planning_teacher_display_style'), 'firstname'); ?>>Prénom seul</option>
                                <option value="fullname" <?php selected(get_option('planning_teacher_display_style'), 'fullname'); ?>>Prénom et Nom</option>
                                <option value="photo_firstname" <?php selected(get_option('planning_teacher_display_style'), 'photo_firstname'); ?>>Photo et Prénom</option>
                            </select>
                            <p class="description">Choisissez comment afficher les informations du professeur sur le planning. Si un professeur n'a pas de photo, son prénom sera affiché à la place.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <label for="planning_sticky_offset">Décalage de l'en-tête fixe</label>
                        </th>
                        <td>
                            <input type="number" 
                                   name="planning_sticky_offset" 
                                   id="planning_sticky_offset"
                                   value="<?php echo esc_attr(get_option('planning_sticky_offset', 0)); ?>"
                                   class="small-text"
                                   min="0"
                                   step="1"
                            > px
                            <p class="description">Distance depuis le haut de la page où l'en-tête du planning deviendra fixe.</p>
                            <div class="sticky-preview" style="margin-top: 10px;">
                                <button type="button" class="button button-secondary" id="previewStickyOffset">
                                    Prévisualiser
                                </button>
                            </div>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Enregistrer les paramètres'); ?>
            </form>
        </div>
        
        <div class="settings-card">
            <h3>Shortcodes disponibles</h3>
            <table class="widefat" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th>Shortcode</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[planning_danse]</code></td>
                        <td>Affiche le planning hebdomadaire complet</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function display_categories_settings() {
        
    $categories = get_option('planning_course_categories');
    
    // Ajouter une nouvelle catégorie
    if (isset($_POST['add_category'])) {
        check_admin_referer('manage_categories_nonce');
        
        $slug = sanitize_title($_POST['category_name']);
        $categories[$slug] = [
            'name' => sanitize_text_field($_POST['category_name']),
            'bg' => sanitize_hex_color($_POST['category_bg']),
            'text' => sanitize_hex_color($_POST['category_text']),
            'tooltip_enabled' => isset($_POST['category_tooltip_enabled']),
            'tooltip_text' => sanitize_textarea_field($_POST['category_tooltip_text'])
        ];
        
        update_option('planning_course_categories', $categories);
        
        // Ajouter un message de succès
        add_settings_error(
            'planning_messages',
            'planning_category_added',
            'La catégorie a été ajoutée avec succès.',
            'updated'
        );
    } else {
        add_settings_error(
            'planning_messages',
            'planning_category_error',
            'Tous les champs sont requis.',
            'error'
        );
    }
    
    // Modifier une catégorie existante
   if (isset($_POST['edit_category'])) {
       check_admin_referer('manage_categories_nonce');
       $slug = $_POST['category_slug'];
       $categories[$slug] = [
           'name' => sanitize_text_field($_POST['category_name']),
           'bg' => sanitize_hex_color($_POST['category_bg']),
           'text' => sanitize_hex_color($_POST['category_text']),
           'tooltip_enabled' => isset($_POST['category_tooltip_enabled']),
            'tooltip_text' => sanitize_textarea_field($_POST['category_tooltip_text'])
       ];
       update_option('planning_course_categories', $categories);
   }
   
    // Supprimer une catégorie
    if (isset($_POST['delete_category'])) {
        check_admin_referer('manage_categories_nonce');
        unset($categories[$_POST['category_slug']]);
        update_option('planning_course_categories', $categories);
    }
    
    ?>
    <div class="wrap">
        <h2>Gestion des Catégories de Cours</h2>
        
        <!-- Formulaire d'ajout -->
        <div class="card" style="max-width: 600px; margin: 20px 0;">
            <h3>Ajouter une catégorie</h3>
            <form method="post">
                <?php wp_nonce_field('manage_categories_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th>Nom de la catégorie</th>
                        <td>
                            <input type="text" name="category_name" required>
                        </td>
                    </tr>
                    <tr>
                        <th>Couleur de fond</th>
                        
                        <td class="color-field-group">
                            <input type="color" name="category_bg" value="#efefef">
                        </td>
                    </tr>
                    <tr>
                        <th>Couleur du texte</th>
                        
                        <td class="color-field-group">
                            <input type="color" name="category_text" value="#ffffff">
                        </td>
                    </tr>
                    <tr>
                        <th>Tooltip</th>
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <label>
                                    <input type="checkbox" name="category_tooltip_enabled" value="1">
                                    Activer le tooltip
                                </label>
                                <textarea name="category_tooltip_text" 
                                          rows="3" 
                                          style="width: 100%; max-width: 400px;"
                                          placeholder="Texte à afficher au survol"></textarea>
                            </div>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="add_category" class="button button-primary">
                    Ajouter la catégorie
                </button>
            </form>
        </div>

        <!-- Liste des catégories -->
       <table class="wp-list-table widefat fixed striped">
           <thead>
               <tr>
                   <th>Nom</th>
                   <th>Identifiant</th>
                   <th>Couleurs</th>
                   <th>Tooltip</th>
                   <th>Actions</th>
               </tr>
           </thead>
           <tbody>
               <?php foreach ($categories as $slug => $category): ?>
               <tr class="category-row">
                   <td class="category-display"><?php echo esc_html($category['name']); ?>
                   </td>
                   <td class="category-display">
                       <?php echo esc_html($slug); ?>
                   </td>
                   <td class="category-display">
                       <div style="display: inline-block; width: 20px; height: 20px; background: <?php echo esc_attr($category['bg']); ?>; border: 1px solid #ccc;"></div>
                       <div style="display: inline-block; width: 20px; height: 20px; background: <?php echo esc_attr($category['text']); ?>; border: 1px solid #ccc;"></div>
                   </td>
                   
                   
                   
                    <td class="category-display">
                        <div style="display: inline; width: 20px;" >
                            <?php echo esc_html($category['tooltip_enabled']); ?>
                        </div>
                        <div style="display: inline;" > - </div>
                        <div style="display: inline;" >
                            <?php echo esc_html($category['tooltip_text']); ?>
                        </div>
                        
                    </td>
                    
                   
                   
                   <td class="category-display">
                       <button class="button button-small edit-category">Modifier</button>
                       <form method="post" style="display: inline;">
                           <?php wp_nonce_field('manage_categories_nonce'); ?>
                           <input type="hidden" name="category_slug" value="<?php echo esc_attr($slug); ?>">
                           <button type="submit" name="delete_category" class="button button-small button-link-delete" 
                                   onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette catégorie ?')">
                               Supprimer
                           </button>
                       </form>
                   </td>
                   
                   <!-- Formulaire d'édition (caché par défaut) -->
                   <td colspan="4" class="category-edit" style="display: none;">
                       <form method="post" class="edit-form">
                           <?php wp_nonce_field('manage_categories_nonce'); ?>
                           <input type="hidden" name="category_slug" value="<?php echo esc_attr($slug); ?>">
                           <div style="display: flex; gap: 20px; align-items: center;">
                                <div>
                                    <label>Nom:</label>
                                    <input type="text" name="category_name" value="<?php echo esc_attr($category['name']); ?>" required>
                                </div>
                                <div class="color-field-group">
                                    <label>Fond:</label>
                                    <input type="color" name="category_bg" value="<?php echo esc_attr($category['bg']); ?>">
                                </div>
                                <div class="color-field-group">
                                    <label>Texte:</label>
                                    <input type="color" name="category_text" value="<?php echo esc_attr($category['text']); ?>">
                                </div>
                                <div class="color-field-group">
                                    <label>Tooltip:</label>
                                    <div style="display: flex; flex-direction: column; gap: 10px;">
                                        <label>
                                            <input type="checkbox" name="category_tooltip_enabled" 
                                                   value="1" <?php checked($category['tooltip_enabled'] ?? false); ?>>
                                            Activer le tooltip
                                        </label>
                                        <textarea name="category_tooltip_text" 
                                                  rows="3" 
                                                  style="width: 100%;"><?php echo esc_textarea($category['tooltip_text'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div>
                                    <button type="submit" name="edit_category" class="button button-primary">Enregistrer</button>
                                    <button type="button" class="button cancel-edit">Annuler</button>
                                </div>
                            </div>
                       </form>
                   </td>
               </tr>
               <?php endforeach; ?>
           </tbody>
       </table>
    </div>
    <style>
   .category-edit {
       background: #f8f8f8;
       padding: 15px;
   }
   .edit-form label {
       display: block;
       margin-bottom: 5px;
   }
   </style>

    <script>
    jQuery(document).ready(function($) {
        $('.edit-category').on('click', function() {
            var $row = $(this).closest('tr');
            $row.find('.category-display').hide();
            $row.find('.category-edit').show();
        });
    
        $('.cancel-edit').on('click', function() {
            var $row = $(this).closest('tr');
            $row.find('.category-edit').hide();
            $row.find('.category-display').show();
        });
    
        // Synchronisation des champs de couleur
        function syncColorFields($colorPicker, $hexInput) {
            $colorPicker.on('input', function() {
                $hexInput.val($(this).val().toUpperCase());
            });
    
            $hexInput.on('input', function() {
                let color = $(this).val();
                if (color.charAt(0) !== '#') {
                    color = '#' + color;
                    $(this).val(color);
                }
                if (color.match(/^#[0-9A-F]{6}$/i)) {
                    $colorPicker.val(color);
                }
            });
        }
    
        // Ajouter des champs hexadécimaux à côté des color pickers
        $('input[type="color"]').each(function() {
            const $colorPicker = $(this);
            const $container = $('<div style="display: flex; gap: 10px; align-items: center;"></div>');
            const $hexInput = $('<input type="text" class="color-hex" style="width: 80px;" value="' + $colorPicker.val().toUpperCase() + '">');
            
            $colorPicker.wrap($container);
            $colorPicker.after($hexInput);
            
            syncColorFields($colorPicker, $hexInput);
        });
    });
    </script>
   
    <?php
       
    }
    
    private function get_category_info($course_name) {
        $categories = get_option('planning_course_categories');
        $first_word = strtok($course_name, " ");
        
        foreach ($categories as $slug => $category) {
            if (stripos($first_word, $category['name']) !== false) {
                return $category;
            }
        }
        return null;
    }
    
    private function display_colors_settings() {
        
    // Récupérer les couleurs des cours existantes
    
    $ribbon_colors = get_option('planning_ribbon_colors');
    
    
    // Récupérer/initialiser les couleurs d'interface
    $interface_colors = get_option('planning_danse_interface_colors', [
        'empty_cell' => '#232323',
        'header_bg' => '#2a2a2a',
        'time_cell_bg' => '#2a2a2a',
        'time_cell_text' => '#888888',
        'background' => '#1a1a1a'
    ]);

    ?>
    <div class="wrap">
        <h2>Couleurs du Planning</h2>
        
        <!-- Couleurs d'interface -->
        <h3>Couleurs de l'interface</h3>
<form method="post" action="options.php">
    <?php settings_fields('planning-danse-interface'); ?>
    <input type="hidden" name="option_page" value="planning-danse-interface">
    <table class="form-table">
        <tr>
            <th>Arrière-plan général</th>
            <td class="color-field-group">
                <input type="color" 
                    name="planning_danse_interface_colors[background]" 
                    value="<?php echo esc_attr($interface_colors['background'] ?? '#1a1a1a'); ?>" />
            </td>
        </tr>
        <tr>
            <th>Cellules vides</th>
            <td>
                <input type="color" 
                    name="planning_danse_interface_colors[empty_cell]" 
                    value="<?php echo esc_attr($interface_colors['empty_cell'] ?? '#232323'); ?>">
            </td>
        </tr>
        <tr>
            <th>En-têtes</th>
            <td>
                <input type="color" 
                    name="planning_danse_interface_colors[header_bg]" 
                    value="<?php echo esc_attr($interface_colors['header_bg'] ?? '#2a2a2a'); ?>">
            </td>
        </tr>
        <tr>
            <th>Arrière-plan colonne horaire</th>
            <td>
                <input type="color" 
                    name="planning_danse_interface_colors[time_cell_bg]" 
                    value="<?php echo esc_attr($interface_colors['time_cell_bg'] ?? '#2a2a2a'); ?>">
            </td>
        </tr>
        <tr>
            <th>Texte colonne horaire</th>
            <td>
                <input type="color" 
                    name="planning_danse_interface_colors[time_cell_text]" 
                    value="<?php echo esc_attr($interface_colors['time_cell_text'] ?? '#888888'); ?>">
            </td>
        </tr>
    </table>
    <?php submit_button('Enregistrer'); ?>
</form>
        <h3>Textes personnalisés</h3>
        <form method="post" action="options.php">
            <?php settings_fields('planning-danse-nospectacle'); ?>
            <table class="form-table">
                <tr>
                    <th>Message "No Spectacle"</th>
                    <td>
                        <input type="text" name="planning_no_spectacle_text" 
                               value="<?php echo esc_attr(get_option('planning_no_spectacle_text')); ?>" 
                               class="regular-text">
                        <p class="description">Texte affiché au survol de l'icône "No Spectacle"</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <h3>Couleurs du ruban "COMPLET"</h3>
        <form method="post" action="options.php">
            <?php settings_fields('planning-danse-ribbon'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label>Couleur de fond du ruban</label>
                    </th>
                    <td class="color-field-group">
                        <input type="color" 
                               class="color-picker"
                               name="planning_ribbon_colors[background]"
                               value="<?php echo esc_attr($ribbon_colors['background']); ?>" />
                        <input type="text" 
                               class="color-hex"
                               value="<?php echo esc_attr($ribbon_colors['background']); ?>" />
                        <button type="button" class="button button-secondary copy-color" 
                                data-clipboard="<?php echo esc_attr($ribbon_colors['background']); ?>">
                            Copier
                        </button>
                    </td>
                </tr>
            <tr>
                <th scope="row">
                    <label>Couleur du texte</label>
                </th>
                <td class="color-field-group">
                    <input type="color" 
                           class="color-picker"
                           name="planning_ribbon_colors[text]"
                           value="<?php echo esc_attr($ribbon_colors['text']); ?>" />
                    <input type="text" 
                           class="color-hex"
                           value="<?php echo esc_attr($ribbon_colors['text']); ?>" />
                    <button type="button" class="button button-secondary copy-color" 
                            data-clipboard="<?php echo esc_attr($ribbon_colors['text']); ?>">
                        Copier
                    </button>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label>Couleur des coins</label>
                </th>
                <td class="color-field-group">
                    <input type="color" 
                           class="color-picker"
                           name="planning_ribbon_colors[corners]"
                           value="<?php echo esc_attr($ribbon_colors['corners']); ?>" />
                    <input type="text" 
                           class="color-hex"
                           value="<?php echo esc_attr($ribbon_colors['corners']); ?>" />
                    <button type="button" class="button button-secondary copy-color" 
                            data-clipboard="<?php echo esc_attr($ribbon_colors['corners']); ?>">
                        Copier
                    </button>
                </td>
            </tr>
        </table>
        <?php submit_button('Enregistrer les couleurs du ruban'); ?>
    </form>

        
    </div>

    <style>
    .color-field-group {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .color-group {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .color-group > div {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .color-hex { 
        width: 80px;
        border: 1px solid #ddd;
        padding: 5px;
    }
    .color-preview {
        margin-top: 5px;
        padding: 10px;
        border-radius: 4px;
        display: inline-block;
    }
    </style>

    <script>
jQuery(document).ready(function($) {
    // Fonction pour gérer la synchronisation des champs de couleur
    function syncColorFields($colorPicker, $hexInput) {
        // Du color picker vers le champ hex
        $colorPicker.on('input change', function() {
            // Sélectionner uniquement le champ hex correspondant en utilisant les attributs data
            const colorType = $(this).data('color-type');
            const courseType = $(this).data('type');
            $hexInput.val($(this).val().toUpperCase());
        });

        // Du champ hex vers le color picker
        $hexInput.on('input change', function() {
            let color = $(this).val();
            if (color.charAt(0) !== '#') {
                color = '#' + color;
                $(this).val(color);
            }
            if (color.match(/^#[0-9A-F]{6}$/i)) {
                $colorPicker.val(color);
                
                // Mettre à jour l'aperçu si nécessaire
                const $preview = $(this).closest('.color-group').find('.color-preview');
                const colorType = $(this).data('color-type');
                if ($preview.length && colorType) {
                    if (colorType === 'bg') {
                        $preview.css('background-color', color);
                    } else if (colorType === 'text') {
                        $preview.css('color', color);
                    }
                }
            }
        });
    }

    // Application pour les couleurs des cours
    $('.color-group').each(function() {
        const $group = $(this);
        
        // Synchronisation pour la couleur de fond
        const $bgColorPicker = $group.find('input[type="color"]:not([id*="text"])');
        const $bgHexInput = $group.find('.color-hex[data-color-type="bg"]');
        if ($bgColorPicker.length && $bgHexInput.length) {
            syncColorFields($bgColorPicker, $bgHexInput);
        }
        
        // Synchronisation pour la couleur du texte
        const $textColorPicker = $group.find('input[type="color"][id*="text"]');
        const $textHexInput = $group.find('.color-hex[data-color-type="text"]');
        if ($textColorPicker.length && $textHexInput.length) {
            syncColorFields($textColorPicker, $textHexInput);
        }
    });

    // Application pour les couleurs d'interface
    $('.color-field-group').each(function() {
        const $colorPicker = $(this).find('input[type="color"]');
        const $hexInput = $(this).find('.color-hex');
        if ($colorPicker.length && $hexInput.length) {
            syncColorFields($colorPicker, $hexInput);
        }
    });

    // Gestion du bouton copier
    $('.copy-color').on('click', function() {
        const $button = $(this);
        const $hexInput = $button.prevAll('.color-hex:first');
        const colorValue = $hexInput.val();

        navigator.clipboard.writeText(colorValue).then(function() {
            const originalText = $button.text();
            $button.text('Copié !');
            setTimeout(function() {
                $button.text(originalText);
            }, 1000);
        });
    });

    // Forcer la mise en majuscule des valeurs hexadécimales
    $('.color-hex').on('blur', function() {
        $(this).val($(this).val().toUpperCase());
    });
});
</script>
    <?php
       
    }
    
    private function display_teachers_settings() {
        
    $teachers = get_option('planning_teachers', []);
    
    // Gérer l'ajout d'un professeur
    if (isset($_POST['add_teacher'])) {
        check_admin_referer('manage_teachers_nonce');
        
        $new_teacher = [
            'id' => uniqid(),
            'firstname' => sanitize_text_field($_POST['teacher_firstname']),
            'lastname' => sanitize_text_field($_POST['teacher_lastname']),
            'photo_url' => esc_url_raw($_POST['teacher_photo']),
            'profile_url' => esc_url_raw($_POST['teacher_url'])
        ];
        
        $teachers[] = $new_teacher;
        update_option('planning_teachers', $teachers);
        
        add_settings_error(
            'planning_messages',
            'planning_teacher_added',
            'Le professeur a été ajouté avec succès.',
            'updated'
        );
    }
    
    // Gérer la modification d'un professeur
    if (isset($_POST['edit_teacher'])) {
        check_admin_referer('manage_teachers_nonce');
        
        $teacher_id = sanitize_text_field($_POST['teacher_id']);
        foreach ($teachers as $key => $teacher) {
            if ($teacher['id'] === $teacher_id) {
                $teachers[$key] = [
                    'id' => $teacher_id,
                    'firstname' => sanitize_text_field($_POST['teacher_firstname']),
                    'lastname' => sanitize_text_field($_POST['teacher_lastname']),
                    'photo_url' => esc_url_raw($_POST['teacher_photo']),
                    'profile_url' => esc_url_raw($_POST['teacher_url'])
                ];
                break;
            }
        }
        update_option('planning_teachers', $teachers);
    }
    
    // Gérer la suppression d'un professeur
    if (isset($_POST['delete_teacher'])) {
        check_admin_referer('manage_teachers_nonce');
        
        $teacher_id = sanitize_text_field($_POST['teacher_id']);
        $teachers = array_filter($teachers, function($teacher) use ($teacher_id) {
            return $teacher['id'] !== $teacher_id;
        });
        update_option('planning_teachers', $teachers);
    }
    
    ?>
    <div class="wrap">
        <h2>Gestion des Professeurs</h2>
        
        <!-- Formulaire d'ajout -->
        <div class="card" style="max-width: 800px; margin: 20px 0; padding: 20px;">
            <h3>Ajouter un professeur</h3>
            <form method="post">
                <?php wp_nonce_field('manage_teachers_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="teacher_firstname">Prénom</label></th>
                        <td>
                            <input type="text" name="teacher_firstname" id="teacher_firstname" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="teacher_lastname">Nom</label></th>
                        <td>
                            <input type="text" name="teacher_lastname" id="teacher_lastname" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="teacher_photo">Photo</label></th>
                        <td>
                            <div class="photo-upload-container">
                                <input type="hidden" name="teacher_photo" id="teacher_photo" class="photo-url">
                                <img src="" class="photo-preview" style="display:none; width:100px; height:100px; border-radius:50%; object-fit:cover; margin-bottom:10px;">
                                <button type="button" class="button select-photo">Choisir une photo</button>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="teacher_url">URL du profil</label></th>
                        <td>
                            <input type="url" name="teacher_url" id="teacher_url" class="regular-text">
                            <p class="description">L'URL de la page de profil du professeur (optionnel)</p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="add_teacher" class="button button-primary">
                    Ajouter le professeur
                </button>
            </form>
        </div>

        <!-- Liste des professeurs -->
        <h3>Professeurs existants</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Photo</th>
                    <th>Prénom</th>
                    <th>Nom</th>
                    <th>URL du profil</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teachers as $teacher): ?>
                <tr class="teacher-row" data-id="<?php echo esc_attr($teacher['id']); ?>">
                    <td class="teacher-display">
                        <img src="<?php echo esc_url($teacher['photo_url']); ?>" 
                             style="width:50px; height:50px; border-radius:50%; object-fit:cover;">
                    </td>
                    <td class="teacher-display">
                        <?php echo esc_html($teacher['firstname']); ?>
                    </td>
                    <td class="teacher-display">
                        <?php echo esc_html($teacher['lastname']); ?>
                    </td>
                    <td class="teacher-display">
                        <?php if (!empty($teacher['profile_url'])): ?>
                            <a href="<?php echo esc_url($teacher['profile_url']); ?>" target="_blank">
                                <?php echo esc_url($teacher['profile_url']); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td class="teacher-display">
                        <button class="button button-small edit-teacher">Modifier</button>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('manage_teachers_nonce'); ?>
                            <input type="hidden" name="teacher_id" value="<?php echo esc_attr($teacher['id']); ?>">
                            <button type="submit" name="delete_teacher" class="button button-small button-link-delete" 
                                    onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce professeur ?')">
                                Supprimer
                            </button>
                        </form>
                    </td>
                    
                    <!-- Formulaire d'édition (caché par défaut) -->
                    <td colspan="5" class="teacher-edit" style="display: none;">
                        <form method="post" class="edit-form">
                            <?php wp_nonce_field('manage_teachers_nonce'); ?>
                            <input type="hidden" name="teacher_id" value="<?php echo esc_attr($teacher['id']); ?>">
                            <div style="display: flex; gap: 20px; align-items: start; padding: 20px;">
                                <div class="photo-upload-container">
                                    <input type="hidden" name="teacher_photo" class="photo-url" value="<?php echo esc_attr($teacher['photo_url']); ?>">
                                    <img src="<?php echo esc_url($teacher['photo_url']); ?>" 
                                         class="photo-preview" 
                                         style="width:100px; height:100px; border-radius:50%; object-fit:cover; margin-bottom:10px;">
                                    <button type="button" class="button select-photo">Changer la photo</button>
                                </div>
                                <div style="flex-grow: 1;">
                                    <div>
                                        <label>Prénom:</label>
                                        <input type="text" name="teacher_firstname" value="<?php echo esc_attr($teacher['firstname']); ?>" required>
                                    </div>
                                    <div style="margin-top: 10px;">
                                        <label>Nom:</label>
                                        <input type="text" name="teacher_lastname" value="<?php echo esc_attr($teacher['lastname']); ?>" required>
                                    </div>
                                    <div style="margin-top: 10px;">
                                        <label>URL du profil:</label>
                                        <input type="url" name="teacher_url" value="<?php echo esc_attr($teacher['profile_url']); ?>" class="regular-text">
                                    </div>
                                </div>
                                <div>
                                    <button type="submit" name="edit_teacher" class="button button-primary">Enregistrer</button>
                                    <button type="button" class="button cancel-edit">Annuler</button>
                                </div>
                            </div>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <style>
    .teacher-edit {
        background: #f8f8f8;
        padding: 15px;
    }
    .edit-form label {
        display: block;
        margin-bottom: 5px;
    }
    .edit-form input[type="text"],
    .edit-form input[type="url"] {
        width: 100%;
        max-width: 300px;
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Fonction pour gérer le sélecteur de média
        function initMediaUploader(container) {
            const button = container.find('.select-photo');
            const urlInput = container.find('.photo-url');
            const preview = container.find('.photo-preview');
            
            button.on('click', function(e) {
                e.preventDefault();
                
                const uploader = wp.media({
                    title: 'Sélectionner une photo de profil',
                    button: {
                        text: 'Utiliser cette photo'
                    },
                    multiple: false
                });
                
                uploader.on('select', function() {
                    const attachment = uploader.state().get('selection').first().toJSON();
                    urlInput.val(attachment.url);
                    preview.attr('src', attachment.url).show();
                });
                
                uploader.open();
            });
        }
        
        // Initialiser tous les sélecteurs de média
        $('.photo-upload-container').each(function() {
            initMediaUploader($(this));
        });
        
        // Gestion de l'édition
        $('.edit-teacher').on('click', function() {
            var $row = $(this).closest('tr');
            $row.find('.teacher-display').hide();
            $row.find('.teacher-edit').show();
        });

        $('.cancel-edit').on('click', function() {
            var $row = $(this).closest('tr');
            $row.find('.teacher-edit').hide();
            $row.find('.teacher-display').show();
        });
    });
    </script>
    <?php
      
    }
    
    private function display_booking_settings() {
    // Initialisation des champs par défaut si non existants
    $default_fields = [
        [
            'type' => 'text',
            'label' => 'Nom',
            'required' => true,
            'placeholder' => 'Votre nom'
        ],
        [
            'type' => 'text',
            'label' => 'Prénom',
            'required' => true,
            'placeholder' => 'Votre prénom'
        ],
        [
            'type' => 'email',
            'label' => 'Email',
            'required' => true,
            'placeholder' => 'votre@email.com'
        ],
        [
            'type' => 'tel',
            'label' => 'Téléphone',
            'required' => true,
            'placeholder' => '06 12 34 56 78'
        ],
        [
            'type' => 'date',
            'label' => 'Date de naissance',
            'required' => true
        ]
    ];

    if (!get_option('booking_form_fields')) {
        update_option('booking_form_fields', $default_fields);
    }

    ?>
    <div class="wrap">
        <h2>Paramètres de réservation des cours d'essai</h2>
        
        <form method="post" action="options.php">
            <?php
            settings_fields('planning-danse-settings');
            do_settings_sections('planning-danse-settings');
            ?>
            
            <div class="settings-card">
                <h3>Période de réservation</h3>
                <table class="form-table">
                    <tr>
                        <th>Période de réservation</th>
                        <td>
                            <label>Du : </label>
                            <input type="date" name="booking_period_start" 
                                   value="<?php echo esc_attr(get_option('booking_period_start')); ?>">
                            <label>Au : </label>
                            <input type="date" name="booking_period_end" 
                                   value="<?php echo esc_attr(get_option('booking_period_end')); ?>">
                        </td>
                    </tr>
                </table>
            </div>

            <div class="settings-card">
                <h3>Configuration des notifications</h3>
                <table class="form-table">
                    <tr>
                        <th>Email de notification</th>
                        <td>
                            <input type="email" name="booking_notification_email" 
                                   value="<?php echo esc_attr(get_option('booking_notification_email')); ?>"
                                   class="regular-text">
                            <p class="description">Les réservations seront envoyées à cette adresse</p>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="settings-card">
                <h3>Test de notification email</h3>
                <button type="button" class="button" id="test-email">
                    Tester l'envoi d'email
                </button>
                <div id="email-test-result"></div>
            </div>

            <div class="settings-card">
                <h3>Configuration Google Sheets</h3>
                <table class="form-table">
                    <tr>
                        <th>ID de la feuille de calcul</th>
                        <td>
                            <input type="text" name="booking_sheet_id" 
                                   value="<?php echo esc_attr(get_option('booking_sheet_id')); ?>"
                                   class="regular-text">
                            <p class="description">ID de la feuille Google Sheets pour les réservations</p>
                        </td>
                    </tr>
                </table>
            </div>

            
            <div class="settings-card">
                <h3>Template Email Client</h3>
                <p class="description">Personnalisez l'email envoyé aux clients. Vous pouvez utiliser les variables suivantes :</p>
                <ul class="template-variables">
                    <li><code>{prenom}</code> - Prénom du client</li>
                    <li><code>{nom}</code> - Nom du client</li>
                    <li><code>{liste_cours}</code> - Liste des cours sélectionnés</li>
                    <li><code>{date}</code> - Date de la demande</li>
                </ul>
                <?php
                $default_template = "Bonjour {prenom},
            
            Nous avons bien reçu votre demande de cours d'essai pour :
            
            {liste_cours}
            
            Nous reviendrons vers vous rapidement pour confirmer votre réservation.
            
            À bientôt,
            L'équipe En Mouvance";
            
                $current_template = get_option('booking_client_email_template', $default_template);
                
                wp_editor(
                    $current_template,
                    'booking_client_email_template',
                    array(
                        'media_buttons' => false,
                        'textarea_name' => 'booking_client_email_template',
                        'textarea_rows' => 15,
                        'teeny' => true,
                        'quicktags' => true,
                        'tinymce' => array(
                            'toolbar1' => 'bold,italic,underline,bullist,numlist,link,undo,redo',
                            'toolbar2' => '',
                        )
                    )
                );
                ?>
                <p class="description">Note : L'email sera envoyé en format texte, le HTML sera converti.</p>
            </div>
            <div class="settings-card">
                <h3>Champs du formulaire de réservation</h3>
                <div id="booking-form-fields">
                    <?php
                    $fields = get_option('booking_form_fields', []);
                    foreach ($fields as $index => $field) {
                        ?>
                        <div class="field-row">
                            <div class="field-type">
                                <select name="booking_form_fields[<?php echo $index; ?>][type]" <?php echo isset($field['fixed']) ? 'disabled' : ''; ?>>
                                    <option value="text" <?php selected($field['type'], 'text'); ?>>Texte</option>
                                    <option value="email" <?php selected($field['type'], 'email'); ?>>Email</option>
                                    <option value="tel" <?php selected($field['type'], 'tel'); ?>>Téléphone</option>
                                    <option value="date" <?php selected($field['type'], 'date'); ?>>Date</option>
                                </select>
                            </div>
                            <div class="field-label">
                                <input type="text" 
                                       name="booking_form_fields[<?php echo $index; ?>][label]" 
                                       value="<?php echo esc_attr($field['label']); ?>"
                                       placeholder="Label du champ"
                                       <?php echo isset($field['fixed']) ? 'readonly' : ''; ?>>
                            </div>
                            <div class="field-required">
                                <label>
                                    <input type="checkbox" 
                                           name="booking_form_fields[<?php echo $index; ?>][required]" 
                                           <?php checked($field['required'], true); ?>
                                           <?php echo isset($field['fixed']) ? 'disabled' : ''; ?>>
                                    Obligatoire
                                </label>
                            </div>
                            <div class="field-placeholder">
                                <input type="text" 
                                       name="booking_form_fields[<?php echo $index; ?>][placeholder]" 
                                       value="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                                       placeholder="Texte d'exemple">
                            </div>
                            <?php if (!isset($field['fixed'])): ?>
                                <button type="button" class="button remove-field">Supprimer</button>
                            <?php endif; ?>
                        </div>
                        <?php
                    }
                    ?>
                </div>
                <button type="button" class="button add-field">Ajouter un champ</button>
            </div>
            

            <?php submit_button('Enregistrer les paramètres'); ?>
        </form>
    </div>

    <style>
    .settings-card {
        background: #fff;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid #ccc;
        border-radius: 4px;
    }
    .field-row {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-bottom: 10px;
        padding: 10px;
        background: #f8f8f8;
        border-radius: 4px;
    }
    .field-type select,
    .field-label input,
    .field-placeholder input {
        min-width: 150px;
    }
    .field-required {
        display: flex;
        align-items: center;
    }
    .template-variables {
    background: #f8f8f8;
    padding: 15px 20px;
    border-left: 4px solid #0073aa;
    margin-bottom: 20px;
    }
    .template-variables code {
        background: #fff;
        padding: 2px 5px;
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        let fieldIndex = <?php echo count($fields); ?>;

        $('.add-field').on('click', function() {
            const newField = `
                <div class="field-row">
                    <div class="field-type">
                        <select name="booking_form_fields[${fieldIndex}][type]">
                            <option value="text">Texte</option>
                            <option value="email">Email</option>
                            <option value="tel">Téléphone</option>
                            <option value="date">Date</option>
                        </select>
                    </div>
                    <div class="field-label">
                        <input type="text" 
                               name="booking_form_fields[${fieldIndex}][label]" 
                               placeholder="Label du champ">
                    </div>
                    <div class="field-required">
                        <label>
                            <input type="checkbox" 
                                   name="booking_form_fields[${fieldIndex}][required]">
                            Obligatoire
                        </label>
                    </div>
                    <div class="field-placeholder">
                        <input type="text" 
                               name="booking_form_fields[${fieldIndex}][placeholder]" 
                               placeholder="Texte d'exemple">
                    </div>
                    <button type="button" class="button remove-field">Supprimer</button>
                </div>
            `;
            $('#booking-form-fields').append(newField);
            fieldIndex++;
        });

        $(document).on('click', '.remove-field', function() {
            $(this).closest('.field-row').remove();
        });
    });
    jQuery(document).ready(function($) {
    $('#test-email').on('click', function() {
        const $result = $('#email-test-result');
        $result.html('Test en cours...').show();
        
        $.post(ajaxurl, {
            action: 'test_notification_email',
            nonce: '<?php echo wp_create_nonce('test-email'); ?>'
        }, function(response) {
            if (response.success) {
                $result.html('✓ Email envoyé avec succès')
                       .css('color', '#4CAF50');
            } else {
                $result.html('✗ Erreur lors de l\'envoi : ' + response.data)
                       .css('color', '#f44336');
            }
        });
    });
});
    </script>
    <?php
}
    
    public function test_notification_email() {
        check_ajax_referer('test-email', 'nonce');
    
        $notification_email = get_option('booking_notification_email');
        if (!$notification_email) {
            $notification_email = get_option('admin_email');
        }
    
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: En Mouvance <noreply@la-maam.com>'
        );
    
        $sent = wp_mail(
            $notification_email,
            'Test de notification - Planning Danse',
            "Ceci est un email de test pour vérifier la configuration des notifications.\n\nSi vous recevez cet email, la configuration est correcte.",
            $headers
        );
    
        if ($sent) {
            wp_send_json_success('Email envoyé à ' . $notification_email);
        } else {
            $error = error_get_last();
            wp_send_json_error('Erreur d\'envoi : ' . ($error['message'] ?? 'Erreur inconnue'));
        }
    }

    private function display_google_settings() {
        // Message de succès d'authentification - À PLACER AU DÉBUT de la fonction
        if (isset($_GET['auth']) && $_GET['auth'] === 'success') {
            ?>
            <script>
            jQuery(document).ready(function($) {
                alert('Connexion à Google réussie !');
            });
            </script>
            <?php
        }
        ?>
        <div class="wrap">
        <h2>Configuration Google API</h2>
        
        <form method="post" action="options.php">
            <?php 
            settings_fields('planning-danse-google');
            do_settings_sections('planning-danse-google');
            ?>
            
            <div class="settings-card">
                <h3>Configuration OAuth2</h3>
                <p class="description">
                    Ces informations sont nécessaires pour la connexion avec Google Sheets. 
                    <a href="https://console.cloud.google.com/" target="_blank">Accéder à Google Cloud Console</a>
                </p>

                <table class="form-table">
                    <tr>
                        <th>Client ID</th>
                        <td>
                            <input type="text" 
                                   name="google_client_id" 
                                   class="regular-text"
                                   value="<?php echo esc_attr(get_option('google_client_id')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>Client Secret</th>
                        <td>
                            <input type="password" 
                                   name="google_client_secret" 
                                   class="regular-text"
                                   value="<?php echo esc_attr(get_option('google_client_secret')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>État de la connexion</th>
                        <td>
                            <?php 
                            $refresh_token = get_option('google_refresh_token');
                            $access_token = get_option('google_access_token');
                            $token_expires = get_option('google_token_expires');
                            
                            if ($refresh_token && $access_token) {
                                echo '<span class="connected">✓ Connecté à Google</span>';
                                if ($token_expires && $token_expires < time()) {
                                    echo ' (Token expiré)';
                                }
                                echo '<br><button type="button" class="button" id="disconnect-google">Déconnecter</button>';
                            } else {
                                echo '<button type="button" class="button button-primary" id="connect-google">Se connecter à Google</button>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="settings-card">
                <h3>Configuration Google Sheets</h3>
                <table class="form-table">
                    <tr>
                        <th>ID de la feuille</th>
                        <td>
                            <input type="text" 
                                   name="booking_sheet_id" 
                                   value="<?php echo esc_attr(get_option('booking_sheet_id')); ?>"
                                   class="regular-text">
                            <p class="description">
                                L'ID se trouve dans l'URL de votre Google Sheet : 
                                docs.google.com/spreadsheets/d/<strong>ID-ICI</strong>/edit
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button('Enregistrer tous les paramètres'); ?>
        </form>

        <?php if ($refresh_token): ?>
        <div class="settings-card">
            <h3>Test de connexion</h3>
            <button type="button" class="button" id="test-google-connection">
                Tester la connexion
            </button>
            <div id="test-result"></div>
        </div>
        <?php endif; ?>
    </div>
    
        <style>
        .settings-card {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .connected {
            color: #4CAF50;
            font-weight: bold;
        }
        #test-result {
            margin-top: 10px;
            padding: 10px;
            display: none;
        }
        </style>
    
        <script>
        jQuery(document).ready(function($) {
            $('#connect-google').on('click', function() {
                const clientId = $('input[name="google_client_id"]').val();
                if (!clientId) {
                    alert('Veuillez d\'abord sauvegarder votre Client ID');
                    return;
                }
    
                // Construction de l'URL d'autorisation Google
                const redirectUri = '<?php echo $this->get_redirect_uri(); ?>';
                const scope = 'https://www.googleapis.com/auth/spreadsheets';
                const authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' +
                    'client_id=' + encodeURIComponent(clientId) +
                    '&redirect_uri=' + encodeURIComponent(redirectUri) +
                    '&response_type=code' +
                    '&scope=' + encodeURIComponent(scope) +
                    '&access_type=offline' +
                    '&prompt=consent';
    
                // Ouvrir la fenêtre d'autorisation Google
                window.open(authUrl, 'GoogleAuth', 'width=600,height=600,menubar=no,toolbar=no');
            });
    
            $('#disconnect-google').on('click', function() {
                if (confirm('Êtes-vous sûr de vouloir déconnecter Google ?')) {
                    $.post(ajaxurl, {
                        action: 'disconnect_google',
                        nonce: '<?php echo wp_create_nonce('google-disconnect'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Erreur lors de la déconnexion');
                        }
                    });
                }
            });
    
            $('#test-google-connection').on('click', function() {
                const $result = $('#test-result');
                $result.html('Test en cours...').show();
                
                $.post(ajaxurl, {
                    action: 'test_google_connection',
                    nonce: '<?php echo wp_create_nonce('google-test'); ?>'
                }, function(response) {
                    if (response.success) {
                        $result.html('✓ Connexion réussie')
                               .css('background-color', '#dff0d8')
                               .css('color', '#3c763d');
                    } else {
                        $result.html('✗ ' + response.data)
                               .css('background-color', '#f2dede')
                               .css('color', '#a94442');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function handle_google_auth() {
        // Ajout de logs pour debug
        error_log('Google Auth Callback triggered');
        error_log('GET params: ' . print_r($_GET, true));
    
        if (!isset($_GET['code'])) {
        wp_die('Code d\'autorisation manquant');
        return;
        }
    
        $code = sanitize_text_field($_GET['code']);
        $client_id = get_option('google_client_id');
        $client_secret = get_option('google_client_secret');
        $redirect_uri = $this->get_redirect_uri();
    
        error_log('Attempting token exchange with params:');
        error_log('Client ID: ' . substr($client_id, 0, 10) . '...');
        error_log('Redirect URI: ' . $redirect_uri);
    
        // Échange du code contre un token
        $token_url = 'https://oauth2.googleapis.com/token';
        $response = wp_remote_post($token_url, array(
            'body' => array(
                'code' => $code,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri' => $redirect_uri,
                'grant_type' => 'authorization_code'
            )
        ));
    
        if (is_wp_error($response)) {
            error_log('WP Error in token exchange: ' . $response->get_error_message());
            wp_die('Erreur : ' . $response->get_error_message());
            return;
        }
    
        $body = json_decode(wp_remote_retrieve_body($response), true);
        error_log('Token response: ' . print_r($body, true));
    
        if (isset($body['error'])) {
            error_log('Google API Error: ' . ($body['error_description'] ?? $body['error']));
            wp_die('Erreur d\'authentification : ' . ($body['error_description'] ?? $body['error']));
            return;
        }
    
        // Sauvegarder les tokens
        update_option('google_access_token', $body['access_token']);
        if (isset($body['refresh_token'])) {
            update_option('google_refresh_token', $body['refresh_token']);
        }
        update_option('google_token_expires', time() + $body['expires_in']);
    
        // Afficher une page de confirmation et rediriger
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Autorisation Google réussie</title>
            <script>
            window.onload = function() {
                if(window.opener) {
                    window.opener.location.href = '<?php echo admin_url('admin.php?page=planning-danse-settings&tab=google&auth=success'); ?>';
                    window.close();
                } else {
                    window.location.href = '<?php echo admin_url('admin.php?page=planning-danse-settings&tab=google&auth=success'); ?>';
                }
            }
            </script>
            <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
                background: #f0f0f1;
            }
            .message {
                text-align: center;
                padding: 20px;
                background: white;
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .success {
                color: #4CAF50;
                font-size: 16px;
                margin-bottom: 10px;
            }
            </style>
        </head>
        <body>
            <div class="message">
                <div class="success">✓ Autorisation Google réussie</div>
                <p>Cette fenêtre va se fermer automatiquement...</p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    private function refresh_google_token() {
        $refresh_token = get_option('google_refresh_token');
        $client_id = get_option('google_client_id');
        $client_secret = get_option('google_client_secret');
    
        if (!$refresh_token || !$client_id || !$client_secret) {
            return false;
        }
    
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'refresh_token' => $refresh_token,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'grant_type' => 'refresh_token'
            )
        ));
    
        if (is_wp_error($response)) {
            return false;
        }
    
        $body = json_decode(wp_remote_retrieve_body($response), true);
    
        if (isset($body['access_token'])) {
            update_option('google_access_token', $body['access_token']);
            update_option('google_token_expires', time() + $body['expires_in']);
            return $body['access_token'];
        }
    
        return false;
    }

    // Chargement des styles CSS
    public function enqueue_styles() {
        //wp_enqueue_style(
        //    'planning-danse-styles',
        //    plugins_url('css/planning-style.css', __FILE__)
        //);
        // force le Chargement des styles CSS
        wp_enqueue_style(
            'planning-danse-styles',
            plugins_url('css/planning-style.css', __FILE__),
            
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'css/planning-style.css')
        );
        
        
        
        wp_enqueue_script(
            'planning-booking',
            plugins_url('js/booking.js', __FILE__),
            array('jquery'),
           //'1.0',
           filemtime(plugin_dir_path(__FILE__) . 'js/booking.js'),
            true
        );
        
        wp_localize_script('planning-booking', 'planningAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('planning_booking_nonce')
        ));
        
        wp_localize_script('planning-booking', 'planningSettings', array(
            'noSpectacleText' => get_option('planning_no_spectacle_text')
        ));
        
        
    }
    
    public function generate_dynamic_css() {
    $interface_colors = is_array(get_option('planning_danse_interface_colors')) ? 
    get_option('planning_danse_interface_colors') : 
    [
        'background' => '#1a1a1a',
        'empty_cell' => '#232323', 
        'header_bg' => '#2a2a2a',
        'time_cell_bg' => '#2a2a2a',
        'time_cell_text' => '#888888'
    ];
    $ribbon_colors = get_option('planning_ribbon_colors');

    // Vérification que $interface_colors est un tableau
    if (!is_array($interface_colors)) {
        $interface_colors = [
            'empty_cell' => '#232323',
            'header_bg' => '#2a2a2a',
            'time_cell_bg' => '#2a2a2a',
            'time_cell_text' => '#888888',
            'background' => '#1a1a1a'
        ];
    }

    // Vérification que $ribbon_colors est un tableau
    if (!is_array($ribbon_colors)) {
        $ribbon_colors = [
            'background' => '#EC365B',
            'text' => '#ffffff',
            'corners' => '#BE2D4A'
        ];
    }

    $css = "
    .planning-container {
        background: {$interface_colors['background']};
    }
    .planning-table {
        background: {$interface_colors['background']};
    }
    .planning-table th {
        background-color: {$interface_colors['header_bg']};
    }
    .time-cell {
        background-color: {$interface_colors['time_cell_bg']};
        color: {$interface_colors['time_cell_text']};
    }
    .empty-cell {
        background-color: {$interface_colors['empty_cell']};
    }
    .ribbon {
        background-color: {$ribbon_colors['background']};
        color: {$ribbon_colors['text']};
    }
    .ribbon:before,
    .ribbon:after {
        border-top-color: {$ribbon_colors['corners']};
    }
    .teacher-name-wrapper {
        position: absolute;
        bottom: 5px;
        right: 8px;
        font-size: 0.9em;
        font-weight: 500;
        background: rgba(0,0,0,0.2);
        padding: 2px 6px;
        border-radius: 4px;
    }
    .teacher-photo-wrapper.with-name {
        display: flex;
        align-items: center;
        gap: 5px;
        position: absolute;
        bottom: 3px;
        right: 5px;
        z-index: 20;
    }
    .teacher-photo-wrapper.with-name .teacher-firstname-label {
        font-size: 0.9em;
        font-weight: 500;
    }
    ";

    wp_add_inline_style('planning-danse-styles', $css);
}

    // Récupération des données depuis Google Sheets
    private function get_sheet_data() {
    $sheet_id = get_option('planning_google_sheet_id');
    $api_key = get_option('planning_google_api_key');
    
    // Débogage des paramètres
    if (empty($sheet_id) || empty($api_key)) {
        return 'Erreur: Sheet ID ou API Key manquant';
    }

    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/A1:R60?key={$api_key}";
    
    // Débogage de la requête
    $response = wp_remote_get($url);
    
    if (is_wp_error($response)) {
        return 'Erreur WordPress: ' . $response->get_error_message();
    }

    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        $body = wp_remote_retrieve_body($response);
        $error_data = json_decode($body, true);
        return 'Erreur API Google (' . $http_code . '): ' . 
               (isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Message inconnu');
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($data['values'])) {
        return 'Erreur: Données invalides reçues de Google Sheets';
    }

    return $data['values'];
}

    private function getDuration($content) {
        // Chercher un pattern "HH:MM à HH:MM" dans le contenu
        if (preg_match('/(\d{2}:\d{2})\s*à\s*(\d{2}:\d{2})/', $content, $matches)) {
            $startTime = strtotime($matches[1]);
            $endTime = strtotime($matches[2]);
            // Calculer le nombre de créneaux de 15 minutes
            return ($endTime - $startTime) / (15 * 60);
        }
        // Durée par défaut : 4 créneaux (1h)
        return 4;
    }

    public function display_planning() {
    $raw_data = $this->get_sheet_data();
        if (is_string($raw_data)) {
            return '<div class="planning-error">' . esc_html($raw_data) . '</div>';
        }

        $data = array_slice($raw_data, 5);
        $skipCells = array_fill(0, 12, 0);
        $filter_menu = $this->generate_filter_menu();

        ob_start();
        $this->add_booking_elements();
        ?>
        <div class="planning-container">
            <div class="planning-header">
                <?php echo $filter_menu; ?>
                
            </div>
            
        
            <div class="planning-body">
                <table class="planning-table">
                    <thead>
                        <tr class="days-row">
                            <th class="time-header"></th>
                            <?php foreach (['LUNDI', 'MARDI', 'MERCREDI', 'JEUDI', 'VENDREDI', 'SAMEDI'] as $day) : ?>
                        <th colspan="2" data-day="<?php echo strtolower($day); ?>"><?php echo $day; ?></th>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="studios-row">
                            <th class="studio-row-first"></th>
                            <?php for ($i = 0; $i < 6; $i++) : ?>
                                <?php $dayLower = strtolower(['LUNDI', 'MARDI', 'MERCREDI', 'JEUDI', 'VENDREDI', 'SAMEDI'][$i]); ?>
                                <th data-day="<?php echo $dayLower; ?>">STUDIO 1</th>
                                <th data-day="<?php echo $dayLower; ?>">STUDIO 2</th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <?php
                    foreach ($this->timeSlots as $time) {
                        // Trouver l'index de la ligne correspondante
                        $rowIndex = -1;
                        foreach ($data as $index => $row) {
                            if (isset($row[0]) && $row[0] === $time) {
                                $rowIndex = $index;
                                break;
                            }
                        }
    
                        echo "<tr>";
                        echo "<td class='time-cell'>$time</td>";
    
                        // Pour chaque studio (12 colonnes)
                        // Pour chaque studio (12 colonnes)
                        for ($col = 1; $col <= 12; $col++) {
                            // Si cette cellule fait partie d'un cours précédent
                            if ($skipCells[$col-1] > 0) {
                                $skipCells[$col-1]--;
                                continue;
                            }
                        
                            if ($rowIndex !== -1 && isset($data[$rowIndex][$col]) && !empty(trim($data[$rowIndex][$col]))) {
                                // On a trouvé un cours
                                $content = trim($data[$rowIndex][$col]);
                                
                                // Calculer le nombre de créneaux depuis le timing
                                $rowspan = $this->getDuration($content);
                                
                                // Marquer les cellules à sauter
                                if ($rowspan > 1) {
                                    $skipCells[$col-1] = $rowspan - 1;
                                }
                        
                                $color_class = $this->get_color_class($content);
                                $category = $this->get_category_info($content); 
                                $tooltip_attr = '';
                        
                                if ($category && 
                                    isset($category['tooltip_enabled']) && 
                                    $category['tooltip_enabled'] && 
                                    !empty($category['tooltip_text'])) {
                                    $tooltip_attr = ' data-category-tooltip="' . esc_attr($category['tooltip_text']) . '"';
                                }
                        
                                $dayIndex = floor(($col - 1) / 2);
                                $days = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
                                $currentDay = $days[$dayIndex];
                            
                                echo "<td class='course $color_class' rowspan='$rowspan' data-col='$col' data-day='$currentDay' $tooltip_attr>";
                                echo $this->format_course_content($content, $col);
                                echo "</td>";
                            } else {
                                $dayIndex = floor(($col - 1) / 2);
                                $days = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
                                $currentDay = $days[$dayIndex];
                            
                                echo "<td class='empty-cell' data-day='$currentDay'></td>";
                            }
                        }
                        echo "</tr>";
                    }
                    ?>
                </table>
            </div>
        </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
        // Script de synchronisation du scroll horizontal
        const planningBody = document.querySelector('.planning-body');
        const planningHeaders = document.querySelector('.planning-headers');
        
        if (planningBody && planningHeaders) {
            planningBody.addEventListener('scroll', function() {
                planningHeaders.scrollLeft = this.scrollLeft;
            });
    
            planningHeaders.addEventListener('scroll', function() {
                planningBody.scrollLeft = this.scrollLeft;
            });
        }
    
        const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        const courses = document.querySelectorAll('.course');
        let activeElement = null;
    
        // Gestionnaire pour le tap sur un cours
        document.addEventListener('click', function(event) {
            const course = event.target.closest('.course');
            
            // Si on clique sur un bouton ou un lien, laisser le comportement par défaut
            if (event.target.matches('button') || event.target.matches('a')) {
                return;
            }
    
            // Ignorer les clics sur les éléments du professeur
            if (event.target.closest('.teacher-link')) {
                return;
            }
            
            // Si on clique en dehors d'un cours, désactiver tout
            if (!course) {
                if (activeElement) {
                    activeElement.classList.remove('touch-active');
                    activeElement = null;
                }
                document.querySelectorAll('.teacher-link.touch-active')
                    .forEach(el => el.classList.remove('touch-active'));
                return;
            }
    
            // Toggle l'état actif du cours cliqué
            if (course === activeElement) {
                course.classList.remove('touch-active');
                activeElement = null;
            } else {
                if (activeElement) {
                    activeElement.classList.remove('touch-active');
                }
                course.classList.add('touch-active');
                activeElement = course;
            }
        });
    
        // Gestionnaire spécifique pour les photos de professeurs
        document.querySelectorAll('.teacher-link').forEach(teacherLink => {
            teacherLink.addEventListener('click', function(event) {
                // Si on clique sur le lien de profil, laisser le comportement par défaut
                if (event.target.closest('.teacher-profile-link')) {
                    return;
                }
                
                // Empêcher la propagation pour ne pas déclencher le click du cours
                event.stopPropagation();
                
                // Toggle le tooltip du professeur
                this.classList.toggle('touch-active');
            });
        });
    
        // Désactiver tous les éléments actifs au scroll
        planningBody.addEventListener('scroll', function() {
            if (activeElement) {
                activeElement.classList.remove('touch-active');
                activeElement = null;
            }
            document.querySelectorAll('.teacher-link.touch-active')
                .forEach(el => el.classList.remove('touch-active'));
        });
    });
        
        const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    
    if (isTouchDevice) {
        const courses = document.querySelectorAll('.course');
        let activeElement = null;
    
        // Gestionnaire spécifique pour les photos de professeurs
        document.querySelectorAll('.teacher-link').forEach(teacherLink => {
            teacherLink.addEventListener('click', function(event) {
                // Si on clique sur le lien de profil, laisser le comportement par défaut
                if (event.target.closest('.teacher-profile-link')) {
                    return;
                }
                
                // Sinon empêcher la navigation
                event.preventDefault();
                event.stopPropagation();
                
                // Fermer le tooltip précédent s'il existe
                const previousActive = document.querySelector('.teacher-link.touch-active');
                if (previousActive && previousActive !== this) {
                    previousActive.classList.remove('touch-active');
                }
                
                // Toggle le tooltip actuel
                this.classList.toggle('touch-active');
            });
        });
    
        // Gestionnaire pour le tap sur un cours (sans inclure le tooltip professeur)
        document.addEventListener('click', function(event) {
            const course = event.target.closest('.course');
            
            // Ignorer les clics sur les éléments du professeur
            if (event.target.closest('.teacher-link')) {
                return;
            }
            
            // Si on clique en dehors d'un cours, on désactive tout
            if (!course) {
                if (activeElement) {
                    activeElement.classList.remove('touch-active');
                    activeElement = null;
                }
                // Fermer aussi les tooltips de professeurs ouverts
                document.querySelectorAll('.teacher-link.touch-active')
                    .forEach(el => el.classList.remove('touch-active'));
                return;
            }
    
            // Si on clique sur un lien ou un bouton dans le cours, on laisse le comportement normal
            if (event.target.closest('a') || event.target.closest('button')) {
                return;
            }
    
            event.preventDefault();
    
            // Si on clique sur un cours différent, on désactive l'ancien
            if (activeElement && activeElement !== course) {
                activeElement.classList.remove('touch-active');
            }
    
            // Toggle l'état actif du cours cliqué
            if (course === activeElement) {
                course.classList.remove('touch-active');
                activeElement = null;
            } else {
                course.classList.add('touch-active');
                activeElement = course;
            }
        });
    
        // Désactiver tous les éléments actifs quand on scroll
        document.querySelector('.planning-body').addEventListener('scroll', function() {
            if (activeElement) {
                activeElement.classList.remove('touch-active');
                activeElement = null;
            }
            document.querySelectorAll('.teacher-link.touch-active')
                .forEach(el => el.classList.remove('touch-active'));
        });
    }
    
    // Ajuster le z-index pour les tooltips sur mobile
    document.querySelectorAll('.course').forEach(course => {
        const updateZIndex = () => {
            const allCourses = document.querySelectorAll('.course');
            allCourses.forEach(c => c.style.zIndex = '1');
            course.style.zIndex = '100';
        };
    
        if (isTouchDevice) {
            course.addEventListener('click', updateZIndex);
        } else {
            course.addEventListener('mouseenter', updateZIndex);
        }
    });
    
    // Ajuster le z-index pour les tooltips sur mobile
    document.querySelectorAll('.course').forEach(course => {
        const updateZIndex = () => {
            const allCourses = document.querySelectorAll('.course');
            allCourses.forEach(c => c.style.zIndex = '1');
            course.style.zIndex = '100';
        };
    
        if (isTouchDevice) {
            course.addEventListener('click', updateZIndex);
        } else {
            course.addEventListener('mouseenter', updateZIndex);
        }
    });
    
    function enableDragToScroll(element) {
        let isDown = false;
        let startX;
        let startY;
        let scrollLeft;
        let scrollTop;
    
        element.addEventListener('mousedown', (e) => {
            isDown = true;
            element.style.cursor = 'grabbing';
            startX = e.pageX - element.offsetLeft;
            startY = e.pageY - element.offsetTop;
            scrollLeft = element.scrollLeft;
            scrollTop = element.scrollTop;
        });
    
        element.addEventListener('mouseleave', () => {
            isDown = false;
            element.style.cursor = 'grab';
        });
    
        element.addEventListener('mouseup', () => {
            isDown = false;
            element.style.cursor = 'grab';
        });
    
        element.addEventListener('mousemove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - element.offsetLeft;
            const y = e.pageY - element.offsetTop;
            const walkX = (x - startX) * 2;
            const walkY = (y - startY) * 2;
            element.scrollLeft = scrollLeft - walkX;
            element.scrollTop = scrollTop - walkY;
        });
    }
    
    // Appliquer aux éléments scrollables
    document.querySelectorAll('.planning-headers, .planning-body').forEach(el => {
        el.style.cursor = 'grab';
        enableDragToScroll(el);
    
        // Pour s'assurer que l'élément est scrollable verticalement si nécessaire
        el.style.overflowY = 'auto';
    });
    </script>
    <?php
    return ob_get_clean();
}

    private function format_course_content($course, $col = 0) {
        $lines = explode("\n", $course);
        $formatted = '';
        
        // Vérifier si le cours est complet
        $isComplet = (stripos($course, 'complet') !== false);
        
        // Vérifier si pas de spectacle
        $noSpectacle = (stripos($course, 'no spectacle') !== false);
        
        // Récupérer la couleur et les infos de catégorie
        $color_class = $this->get_color_class($course);
        $category = $this->get_category_info($course);
        $tooltip_attr = '';
        if ($category && 
            isset($category['tooltip_enabled']) && 
            $category['tooltip_enabled'] && 
            !empty($category['tooltip_text'])) {
            $tooltip_attr = ' data-category-tooltip="' . esc_attr($category['tooltip_text']) . '"';
        }
        
        $teacherFirstname = null;
        $teacherInfo = null;
        
        // Chercher le prénom du professeur dans le contenu
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                // Vérifier si cette ligne correspond à un prénom de professeur
                $teachers = get_option('planning_teachers', []);
                foreach ($teachers as $teacher) {
                    if (stripos($line, $teacher['firstname']) !== false) {
                        $teacherFirstname = $teacher['firstname'];
                        $teacherInfo = $teacher;
                        break 2;
                    }
                }
            }
        }
        
        // Ajouter le bandeau COMPLET si nécessaire
        if ($isComplet) {
            $formatted .= '<div class="ribbon-wrapper"' . $tooltip_attr . '><div class="ribbon">COMPLET</div></div>';
        }
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                // Masquer les mots-clés, le nom du professeur et la ligne de label du texte original
                $line = preg_replace('/\b(complet|no spectacle)\b/i', '', $line);
                // Ne pas afficher la ligne si elle contient uniquement le prénom du professeur
                if ($teacherFirstname && stripos($line, $teacherFirstname) !== false) {
                    continue;
                }
                // Ne pas afficher la ligne si elle contient un label
                if (preg_match('/label="[^"]+"/i', $line)) {
                    continue;
                }
                
                $line = trim($line);
                if (!empty($line)) {
                    $formatted .= "<div class='course-line'>" . esc_html($line) . "</div>";
                }
            }
        }
    
        // Ajouter l'icône no spectacle si nécessaire
        if ($noSpectacle) {
            $tooltip_text = esc_attr(get_option('planning_no_spectacle_text'));
            $formatted .= '<div class="no-spectacle-icon" data-tooltip="' . $tooltip_text . '">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">
                                <path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                            </svg>
                          </div>';
        }
    
        // Gérer l'affichage du professeur en fonction du réglage
        if ($teacherInfo) {
            $teacher_display_style = get_option('planning_teacher_display_style', 'photo');
            $has_photo = !empty($teacherInfo['photo_url']);
            $photo_url = $has_photo ? esc_url($teacherInfo['photo_url']) : '';
            $profile_url = esc_url($teacherInfo['profile_url']);
            $firstname = esc_html($teacherInfo['firstname']);
            $full_name = esc_html($teacherInfo['firstname'] . ' ' . $teacherInfo['lastname']);
            $teacher_html = '';

            // Si un affichage avec photo est demandé mais qu'il n'y en a pas, on affiche le prénom
            if (!$has_photo && ($teacher_display_style === 'photo' || $teacher_display_style === 'photo_firstname')) {
                $teacher_display_style = 'firstname';
            }

            switch ($teacher_display_style) {
                case 'photo':
                    $teacher_html = '<div class="teacher-photo-wrapper">';
                    $teacher_html .= '<div class="teacher-link' . ($profile_url ? ' has-profile' : '') . '">';
                    $teacher_html .= '<img src="' . $photo_url . '" alt="' . $full_name . '" class="teacher-photo">';
                    $teacher_html .= '<div class="teacher-tooltip">';
                    $teacher_html .= '<div class="teacher-tooltip-content">';
                    $teacher_html .= '<img src="' . $photo_url . '" alt="' . $full_name . '" class="teacher-tooltip-photo">';
                    $teacher_html .= '<div class="teacher-tooltip-info">';
                    $teacher_html .= '<div class="teacher-tooltip-name">' . $full_name . '</div>';
                    if ($profile_url) {
                        $teacher_html .= '<a href="' . $profile_url . '" class="teacher-profile-link">Voir le profil</a>';
                    }
                    $teacher_html .= '</div></div></div></div></div>';
                    break;

                case 'firstname':
                    $teacher_html = '<div class="teacher-name-wrapper">' . $firstname . '</div>';
                    break;

                case 'fullname':
                    $teacher_html = '<div class="teacher-name-wrapper">' . $full_name . '</div>';
                    break;

                case 'photo_firstname':
                    $teacher_html = '<div class="teacher-photo-wrapper with-name">';
                    $teacher_html .= '<div class="teacher-link' . ($profile_url ? ' has-profile' : '') . '">';
                    $teacher_html .= '<img src="' . $photo_url . '" alt="' . $full_name . '" class="teacher-photo">';
                    $teacher_html .= '<div class="teacher-tooltip">';
                    $teacher_html .= '<div class="teacher-tooltip-content">';
                    $teacher_html .= '<img src="' . $photo_url . '" alt="' . $full_name . '" class="teacher-tooltip-photo">';
                    $teacher_html .= '<div class="teacher-tooltip-info">';
                    $teacher_html .= '<div class="teacher-tooltip-name">' . $full_name . '</div>';
                    if ($profile_url) {
                        $teacher_html .= '<a href="' . $profile_url . '" class="teacher-profile-link">Voir le profil</a>';
                    }
                    $teacher_html .= '</div></div></div></div>';
                    $teacher_html .= '<span class="teacher-firstname-label">' . $firstname . '</span>';
                    $teacher_html .= '</div>';
                    break;
            }
            $formatted .= $teacher_html;
        }

        // Vérifier la période de réservation
        $start = get_option('booking_period_start');
        $end = get_option('booking_period_end');
        $today = date('Y-m-d');
        $isBookingPeriod = $today >= $start && $today <= $end;
        
        // Ajouter le bouton uniquement si le cours n'est pas complet
    
        if ($isBookingPeriod && !$isComplet && !stripos($course, 'sur audition')) {
            // Utiliser la même méthode getDuration pour extraire l'heure
            $timeMatch = [];
            if (preg_match('/(\d{2}:\d{2})\s*à\s*(\d{2}:\d{2})/', $course, $timeMatch)) {
                $courseTime = "de {$timeMatch[1]} à {$timeMatch[2]}";
            } else {
                $courseTime = "";
            }
        
            // Déterminer le jour
            $days = ['LUNDI', 'MARDI', 'MERCREDI', 'JEUDI', 'VENDREDI', 'SAMEDI'];
            $dayIndex = floor(($col - 1) / 2);
            $currentDay = isset($days[$dayIndex]) ? $days[$dayIndex] : '';
        
            $courseData = [
                'title' => $course,  // Envoyons le contenu complet au lieu de trim($lines[0])
                'time' => $courseTime,
                'day' => $currentDay,
                'teacher' => $teacherInfo ? $teacherInfo['firstname'] . ' ' . $teacherInfo['lastname'] : ''
            ];
            
            $formatted .= '<button class="book-trial" 
                data-course=\'' . json_encode($courseData, JSON_HEX_APOS | JSON_HEX_QUOT) . '\'>
                Essayer
            </button>';
        }
        
        

    
    return $formatted;
}

    private function get_color_class($course_name) {
        $categories = get_option('planning_course_categories');
        
        // Récupérer le premier mot du contenu du cours
        $first_word = strtok($course_name, " ");
        
        foreach ($categories as $slug => $category) {
            if (stripos($first_word, $category['name']) !== false) {
                add_action('wp_footer', function() use ($slug, $category) {
                    echo "<style>.course.$slug { background-color: {$category['bg']}; color: {$category['text']}; }</style>";
                });
                return $slug;
            }
        }
        return '';
    }
    
    private function appendToGoogleSheet($data) {
    $sheet_id = get_option('planning_google_sheet_id');
    $api_key = get_option('planning_google_api_key');
    
    if (empty($sheet_id) || empty($api_key)) {
        return new WP_Error('missing_config', 'Configuration Google Sheets manquante');
    }

    // Créer le tableau de données dans le format attendu
    $values = array(
        array(
            '', // CODE
            $data['nom'], // NOM
            $data['prenom'], // Prénom
            '', // ETAT
            $data['cours'], // COURS
            '', // DISPO
            '', // ATT
            $data['naiss'], // NAISS
            '', // AGE 30/09
            $data['email'], // email
            $data['tel'], // TEL
            '', // HISTORIQUE
            '', // REMARQUE  
            '', // ACTION
            '', // JOUR
            '', // SOURCE
            date('Y-m-d'), // DATE
            '', // DATE ESSAI
            '' // BLACK LIST
        )
    );

    $body = array(
        'values' => $values,
        'majorDimension' => 'ROWS'
    );

    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/A:S:append?valueInputOption=RAW";
    
    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode($body),
        'method' => 'POST'
    );

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (!empty($body['error'])) {
        return new WP_Error(
            'google_sheets_error',
            $body['error']['message'] ?? 'Erreur lors de l\'ajout des données'
        );
    }

    return true;
}
    
    
}

// Initialisation du plugin
new PlanningDansePlugin();

