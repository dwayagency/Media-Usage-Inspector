<?php
/**
 * Plugin Name: Media Usage Inspector
 * Description: Scansiona /wp-content/uploads e trova immagini non utilizzate (con supporto multisite e builder). Consente filtri e cancellazione massiva. Voce in Media.
 * Version: 1.0.1
 * Author: Alessandro Molinari (DWAY Agency)
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Media_Usage_Inspector')) {

class Media_Usage_Inspector {
    private $page_slug = 'media-usage-inspector';
    private $capability = 'upload_files';
    private $uploads_dir;
    private $uploads_url;
    private $is_network_page = false;

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_pages']);
        if (is_multisite()) {
            add_action('network_admin_menu', [$this, 'add_network_page']);
            add_action('network_admin_edit_mui_scan', [$this, 'handle_scan']);
            add_action('network_admin_edit_mui_delete', [$this, 'handle_delete']);
        }
        add_action('admin_post_mui_scan', [$this, 'handle_scan']);
        add_action('admin_post_mui_delete', [$this, 'handle_delete']);
    }

    public function add_admin_pages() {
		// Pagina di livello principale (non sotto "Media")
		add_menu_page(
			__('Verifica utilizzo immagini', 'media-usage-inspector'),
			__('Verifica immagini', 'media-usage-inspector'),
			$this->capability,
			$this->page_slug,
			[$this, 'render_page'],
			'dashicons-format-gallery',
			59
		);
    }

    public function add_network_page() {
        add_menu_page(
            __('Verifica immagini (Network)', 'media-usage-inspector'),
            __('Verifica immagini', 'media-usage-inspector'),
            'manage_network',
            $this->page_slug . '-network',
            function () { $this->is_network_page = true; $this->render_page(); },
            'dashicons-format-gallery',
            59
        );
    }

    private function setup_uploads_context() {
        $up = wp_upload_dir();
        $this->uploads_dir = wp_normalize_path($up['basedir']);
        $this->uploads_url = $up['baseurl'];
    }

    public function render_page() {
        if (!current_user_can($this->capability) && !current_user_can('manage_network')) {
            wp_die(__('Permessi insufficienti.', 'media-usage-inspector'));
        }
        $this->setup_uploads_context();

        $scan_all = isset($_GET['mui_scan_all']) && $this->is_network_page && current_user_can('manage_network');

        $default_from = '';
        $default_to   = '';
        $default_sub  = '';

        $from = isset($_GET['mui_from']) ? sanitize_text_field($_GET['mui_from']) : $default_from;
        $to   = isset($_GET['mui_to']) ? sanitize_text_field($_GET['mui_to']) : $default_to;
        $sub  = isset($_GET['mui_sub']) ? ltrim(trim(sanitize_text_field($_GET['mui_sub'])), '/\\') : $default_sub;

        $results = null;
        if (isset($_GET['ran']) && $_GET['ran'] === '1') {
            // Visualizza i risultati passati tramite transient
            $key = isset($_GET['key']) ? sanitize_key($_GET['key']) : '';
            if ($key) {
                $results = $this->is_network_page ? get_site_transient("mui_results_$key") : get_transient("mui_results_$key");
            }
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html($this->is_network_page ? __('Verifica immagini (Network)', 'media-usage-inspector') : __('Verifica utilizzo immagini', 'media-usage-inspector')); ?></h1>

            <p><?php echo esc_html__('Scansiona la cartella uploads e individua i file immagine non referenziati nel sito. Supporto per builder, postmeta, options, widget e menù.', 'media-usage-inspector'); ?></p>

            <form method="post" action="<?php echo esc_url($this->is_network_page ? network_admin_url('admin-post.php') : admin_url('admin-post.php')); ?>" style="margin-top:16px;margin-bottom:16px;">
                <?php wp_nonce_field('mui_scan'); ?>
                <input type="hidden" name="action" value="mui_scan" />
                <?php if ($this->is_network_page && current_user_can('manage_network')): ?>
                    <input type="hidden" name="mui_network_scan" value="1" />
                    <label style="display:block;margin:8px 0;">
                        <input type="checkbox" name="mui_scan_all" value="1" <?php checked($scan_all); ?> />
                        <?php echo esc_html__('Scansiona tutti i siti del network', 'media-usage-inspector'); ?>
                    </label>
                <?php endif; ?>

                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div>
                        <label for="mui_from"><?php echo esc_html__('Da data (YYYY-MM-DD)', 'media-usage-inspector'); ?></label><br/>
                        <input type="date" id="mui_from" name="mui_from" value="<?php echo esc_attr($from); ?>" />
                    </div>
                    <div>
                        <label for="mui_to"><?php echo esc_html__('A data (YYYY-MM-DD)', 'media-usage-inspector'); ?></label><br/>
                        <input type="date" id="mui_to" name="mui_to" value="<?php echo esc_attr($to); ?>" />
                    </div>
                    <div>
                        <label for="mui_sub"><?php echo esc_html__('Sottocartella (es. 2024/10, products)', 'media-usage-inspector'); ?></label><br/>
                        <input type="text" id="mui_sub" name="mui_sub" placeholder="es. 2025/09" value="<?php echo esc_attr($sub); ?>" />
                    </div>
                    <div>
                        <button class="button button-primary"><?php echo esc_html__('Esegui scansione', 'media-usage-inspector'); ?></button>
                    </div>
                </div>
            </form>

            <?php if ($results && !empty($results['rows'])): ?>
                <?php
                    $delete_url = $this->is_network_page ? network_admin_url('admin-post.php') : admin_url('admin-post.php');
                ?>
                <form method="post" action="<?php echo esc_url($delete_url); ?>">
                    <?php wp_nonce_field('mui_delete'); ?>
                    <input type="hidden" name="action" value="mui_delete" />
                    <input type="hidden" name="mui_results_key" value="<?php echo esc_attr(isset($_GET['key']) ? sanitize_key($_GET['key']) : ''); ?>" />
                    <p><strong><?php echo esc_html(sprintf(_n('%d file non utilizzato trovato', '%d file non utilizzati trovati', $results['count'], 'media-usage-inspector'), $results['count'])); ?></strong></p>

                    <?php if (!empty($results['notice'])): ?>
                        <div class="notice notice-warning"><p><?php echo esc_html($results['notice']); ?></p></div>
                    <?php endif; ?>

                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:40px;"><input type="checkbox" onclick="document.querySelectorAll('.mui-chk').forEach(c=>c.checked=this.checked);" /></th>
                                <th><?php echo esc_html__('Anteprima', 'media-usage-inspector'); ?></th>
                                <th><?php echo esc_html__('Percorso', 'media-usage-inspector'); ?></th>
                                <th><?php echo esc_html__('Dimensione', 'media-usage-inspector'); ?></th>
                                <th><?php echo esc_html__('Ultima modifica', 'media-usage-inspector'); ?></th>
                                <?php if (!empty($results['site'])): ?>
                                <th><?php echo esc_html__('Sito', 'media-usage-inspector'); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($results['rows'] as $idx => $row): ?>
                            <tr>
                                <td>
                                    <input class="mui-chk" type="checkbox" name="mui_files[]" value="<?php echo esc_attr($idx); ?>" />
                                </td>
                                <td>
                                    <?php
                                    $src = esc_url($row['url']);
                                    echo '<img src="'. $src .'" style="max-width:80px;height:auto;border:1px solid #ddd;padding:2px;background:#fff;" loading="lazy" />';
                                    ?>
                                </td>
                                <td><code><?php echo esc_html($row['relpath']); ?></code></td>
                                <td><?php echo esc_html(size_format($row['size'])); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $row['mtime'])); ?></td>
                                <?php if (!empty($results['site'])): ?>
                                <td><?php echo esc_html($row['site_name']); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:12px;">
                        <button class="button button-secondary" onclick="document.querySelectorAll('.mui-chk').forEach(c=>c.checked=false);return false;"><?php echo esc_html__('Deseleziona tutto', 'media-usage-inspector'); ?></button>
                        <button class="button button-primary" style="margin-left:8px;"><?php echo esc_html__('Elimina selezionati', 'media-usage-inspector'); ?></button>
                    </p>
                </form>
            <?php elseif ($results && empty($results['rows'])): ?>
                <div class="notice notice-success"><p><?php echo esc_html__('Nessun file non utilizzato trovato con i filtri selezionati.', 'media-usage-inspector'); ?></p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_scan() {
        if (!current_user_can($this->capability) && !current_user_can('manage_network')) {
            wp_die(__('Permessi insufficienti.', 'media-usage-inspector'));
        }
        check_admin_referer('mui_scan');

        $this->setup_uploads_context();

        $from = isset($_POST['mui_from']) ? sanitize_text_field($_POST['mui_from']) : '';
        $to   = isset($_POST['mui_to']) ? sanitize_text_field($_POST['mui_to']) : '';
        $sub  = isset($_POST['mui_sub']) ? ltrim(trim(sanitize_text_field($_POST['mui_sub'])), '/\\') : '';

        $scan_all = isset($_POST['mui_scan_all']) && is_multisite() && current_user_can('manage_network');
        $is_network = $scan_all || (isset($_POST['mui_network_scan']) && current_user_can('manage_network'));

        $blogs_scanned = [];
        $rows = [];
        $notice = '';

        if ($scan_all) {
            $sites = get_sites(['number' => 0]);
            foreach ($sites as $site) {
                $bid = (int)$site->blog_id;
                $blogs_scanned[] = $bid;
                switch_to_blog($bid);
                $this->setup_uploads_context();
                $site_rows = $this->scan_site($from, $to, $sub, get_bloginfo('name'));
                $rows = array_merge($rows, $site_rows);
                restore_current_blog();
            }
        } else {
            $blogs_scanned[] = get_current_blog_id();
            $rows = $this->scan_site($from, $to, $sub, get_bloginfo('name'));
        }

        // Salva risultati in transient per visualizzazione
        $key = wp_generate_password(12, false, false);
        $payload = [
            'rows'  => $rows,
            'count' => count($rows),
            'notice'=> $notice,
            'blogs' => $blogs_scanned,
            'site'  => $scan_all ? 'network' : '',
        ];
        
        if ($is_network) {
            set_site_transient("mui_results_$key", $payload, 15 * MINUTE_IN_SECONDS);
        } else {
            set_transient("mui_results_$key", $payload, 15 * MINUTE_IN_SECONDS);
        }

        // Torna alla pagina con i risultati
		if ($is_network && is_multisite()) {
			$base = network_admin_url('admin.php?page=' . $this->page_slug . '-network');
		} else {
			$base = admin_url('admin.php?page=' . $this->page_slug);
		}
        
        $url  = add_query_arg([
            'ran' => '1',
            'key' => $key,
            'mui_from' => $from,
            'mui_to'   => $to,
            'mui_sub'  => $sub,
        ], $base);

        wp_safe_redirect($url);
        exit;
    }

    private function scan_site($from, $to, $sub, $site_name) {
        $used = $this->collect_used_uploads_urls();
        $files = $this->collect_upload_files($sub, $from, $to);

        $used_paths = $this->normalize_used_to_paths($used);

        $current_blog_id = get_current_blog_id();

        $rows = [];
        foreach ($files as $file) {
            $rel = $this->rel_from_full($file);
            if (!$rel) continue;

            // Escludi se usato (controlla anche varianti -WxH)
            if ($this->is_file_marked_used($rel, $used_paths)) continue;

            $rows[] = [
                'fullpath'  => $file,
                'relpath'   => $rel,
                'url'       => $this->url_from_rel($rel),
                'size'      => @filesize($file) ?: 0,
                'mtime'     => @filemtime($file) ?: 0,
                'site_name' => $site_name,
                'blog_id'   => $current_blog_id,
            ];
        }
        return $rows;
    }

    private function collect_upload_files($sub, $from, $to) {
        $dir = $this->uploads_dir;
        if ($sub) {
            $cand = wp_normalize_path(trailingslashit($dir) . $sub);
            if (strpos($cand, $dir) === 0 && is_dir($cand)) {
                $dir = $cand;
            }
        }
        $from_ts = $from ? strtotime($from . ' 00:00:00') : 0;
        $to_ts   = $to ? strtotime($to . ' 23:59:59') : 0;

        $out = [];
        if (!is_dir($dir)) return $out;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $allowed = array('jpg','jpeg','png','gif','webp','avif','svg','bmp');
        foreach ($it as $path => $info) {
            if (!$info->isFile()) continue;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) continue;

            $mtime = @filemtime($path) ?: 0;
            if ($from_ts && $mtime < $from_ts) continue;
            if ($to_ts && $mtime > $to_ts) continue;

            $out[] = wp_normalize_path($path);
        }
        return $out;
    }

    private function collect_used_uploads_urls() {
        global $wpdb;

        $uploads_like = '/wp-content/uploads/';

        // Usa un set per deduplicare al volo e ridurre memoria
        $urls_set = [];

        // 1) Posts (contenuto + excerpt) in batch per ID
        $last_id = 0;
        $batch = 500;
        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content, post_excerpt
                 FROM {$wpdb->posts}
                 WHERE ID > %d
                   AND post_status IN ('publish','pending','draft','private','inherit','future')
                 ORDER BY ID ASC
                 LIMIT %d",
                $last_id,
                $batch
            ));
            if (empty($rows)) break;
            foreach ($rows as $r) {
                foreach ($this->extract_upload_urls($r->post_content) as $u) { $urls_set[$u] = true; }
                if (!empty($r->post_excerpt)) {
                    foreach ($this->extract_upload_urls($r->post_excerpt) as $u) { $urls_set[$u] = true; }
                }
                $last_id = (int)$r->ID;
            }
            if (count($rows) < $batch) break;
        }

        // Helper per iterare meta/options per PK incrementale
        $like = '%' . $wpdb->esc_like($uploads_like) . '%';

        // 2) Postmeta (inclusi builder/ACF/Elementor/WPBakery)
        $last_meta_id = 0;
        $batch_meta = 2000;
        while (true) {
            $vals = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_id > %d AND meta_value LIKE %s
                 ORDER BY meta_id ASC
                 LIMIT %d",
                $last_meta_id, $like, $batch_meta
            ));
            if (empty($vals)) break;
            foreach ($vals as $val) {
                foreach ($this->extract_upload_urls($val) as $u) { $urls_set[$u] = true; }
            }
            // Aggiorna last_meta_id con l'ultimo meta_id letto
            $last_meta_id = (int)$wpdb->get_var("SELECT MAX(meta_id) FROM {$wpdb->postmeta}");
            if (count($vals) < $batch_meta) break;
        }

        // 3) Options (widget, impostazioni varie)
        $last_option_id = 0;
        $batch_opt = 2000;
        while (true) {
            $vals = $wpdb->get_col($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options}
                 WHERE option_id > %d AND option_value LIKE %s
                 ORDER BY option_id ASC
                 LIMIT %d",
                $last_option_id, $like, $batch_opt
            ));
            if (empty($vals)) break;
            foreach ($vals as $val) {
                foreach ($this->extract_upload_urls($val) as $u) { $urls_set[$u] = true; }
            }
            $last_option_id = (int)$wpdb->get_var("SELECT MAX(option_id) FROM {$wpdb->options}");
            if (count($vals) < $batch_opt) break;
        }

        // 4) Term meta
        if ($this->table_exists($wpdb->termmeta)) {
            $last_tmeta_id = 0;
            $batch_tmeta = 2000;
            while (true) {
                $vals = $wpdb->get_col($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->termmeta}
                     WHERE meta_id > %d AND meta_value LIKE %s
                     ORDER BY meta_id ASC
                     LIMIT %d",
                    $last_tmeta_id, $like, $batch_tmeta
                ));
                if (empty($vals)) break;
                foreach ($vals as $val) {
                    foreach ($this->extract_upload_urls($val) as $u) { $urls_set[$u] = true; }
                }
                $last_tmeta_id = (int)$wpdb->get_var("SELECT MAX(meta_id) FROM {$wpdb->termmeta}");
                if (count($vals) < $batch_tmeta) break;
            }
        }

        // 5) User meta
        if ($this->table_exists($wpdb->usermeta)) {
            $last_umeta_id = 0;
            $batch_umeta = 2000;
            while (true) {
                $vals = $wpdb->get_col($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->usermeta}
                     WHERE umeta_id > %d AND meta_value LIKE %s
                     ORDER BY umeta_id ASC
                     LIMIT %d",
                    $last_umeta_id, $like, $batch_umeta
                ));
                if (empty($vals)) break;
                foreach ($vals as $val) {
                    foreach ($this->extract_upload_urls($val) as $u) { $urls_set[$u] = true; }
                }
                $last_umeta_id = (int)$wpdb->get_var("SELECT MAX(umeta_id) FROM {$wpdb->usermeta}");
                if (count($vals) < $batch_umeta) break;
            }
        }

        // 6) Menù (nav_menu_item meta) — sotto postmeta con meta_key filtro, in batch
        $last_menu_id = 0;
        $batch_menu = 2000;
        while (true) {
            $vals = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_id > %d
                   AND meta_key IN ('_menu_item_url','_menu_item_xfn','_menu_item_target','_menu_item_attr_title')
                   AND meta_value LIKE %s
                 ORDER BY meta_id ASC
                 LIMIT %d",
                $last_menu_id, $like, $batch_menu
            ));
            if (empty($vals)) break;
            foreach ($vals as $val) {
                foreach ($this->extract_upload_urls($val) as $u) { $urls_set[$u] = true; }
            }
            $last_menu_id = (int)$wpdb->get_var("SELECT MAX(meta_id) FROM {$wpdb->postmeta}");
            if (count($vals) < $batch_menu) break;
        }

        return array_keys($urls_set);
    }

    private function table_exists($table) {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        return !empty($exists);
    }

    private function extract_upload_urls($text) {
        if (!is_string($text) || $text === '') return [];
        $found = [];

        // pattern assoluto
        $abs = [];
        preg_match_all('#https?://[^"\']+(/wp-content/uploads/[^"\')\s]+)#i', $text, $abs);
        if (!empty($abs[1])) $found = array_merge($found, $abs[1]);

        // pattern relativo
        $rel = [];
        preg_match_all('#(/wp-content/uploads/[^"\')\s]+)#i', $text, $rel);
        if (!empty($rel[1])) $found = array_merge($found, $rel[1]);

        // Pulisci ancore/parametri
        $clean = [];
        foreach ($found as $u) {
            $u = preg_replace('#[\?\#].*$#', '', $u);
            $clean[] = $u;
        }
        return $clean;
    }

    private function normalize_used_to_paths($urls) {
        // Trasforma /wp-content/uploads/.. => percorso relativo a basedir
        $out = [];
        $basedir = $this->uploads_dir;
        $baseurl = $this->uploads_url;

        foreach ($urls as $u) {
            // Rimuovi dominio se presente
            if (strpos($u, '/wp-content/uploads/') !== false) {
                $pos = strpos($u, '/wp-content/uploads/');
                $rel = substr($u, $pos + strlen('/wp-content/uploads/'));
            } else {
                // fallback
                $rel = ltrim($u, '/');
            }
            $rel = ltrim($rel, '/\\');
            $rel = str_replace(['\\'], '/', $rel);

            // Normalizza (es. URL encoded)
            $rel = urldecode($rel);

            $out[$rel] = true;
        }
        return $out;
    }

    private function rel_from_full($full) {
        $full = wp_normalize_path($full);
        $base = trailingslashit($this->uploads_dir);
        if (strpos($full, $base) === 0) {
            return ltrim(substr($full, strlen($base)), '/\\');
        }
        return '';
    }

    private function url_from_rel($rel) {
        return trailingslashit($this->uploads_url) . str_replace('\\', '/', $rel);
    }

    private function is_file_marked_used($rel, $used_paths) {
        if (isset($used_paths[$rel])) return true;

        // Se è una variante -WxH, cerca l'originale; se è l'originale, verifica eventuali varianti usate.
        $pi = pathinfo($rel);
        $name = $pi['filename'] ?? '';
        $ext  = isset($pi['extension']) ? ('.' . $pi['extension']) : '';

        // Se è una dimensione -123x456
        if (preg_match('#^(.*)-\d+x\d+$#', $name, $m)) {
            $orig_rel = ($pi['dirname'] !== '.' ? $pi['dirname'] . '/' : '') . $m[1] . $ext;
            if (isset($used_paths[$orig_rel])) return true;
        } else {
            // Controlla se una variante è usata
            foreach (array_keys($used_paths) as $u) {
                if (strpos($u, $pi['dirname'] . '/') === 0) {
                    // stesse cartelle e stesso base name con -WxH
                    if (preg_match('#^' . preg_quote(($pi['dirname'] !== '.' ? $pi['dirname'] . '/' : '') . $name, '#') . '-\d+x\d+' . preg_quote($ext, '#') . '$#', $u)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function handle_delete() {
        if (!current_user_can($this->capability) && !current_user_can('manage_network')) {
            wp_die(__('Permessi insufficienti.', 'media-usage-inspector'));
        }
        check_admin_referer('mui_delete');

        $file_indices = isset($_POST['mui_files']) && is_array($_POST['mui_files']) ? array_map('intval', $_POST['mui_files']) : [];
        $results_key = isset($_POST['mui_results_key']) ? sanitize_key($_POST['mui_results_key']) : '';

        $deleted = 0;
        $errors  = 0;
        $is_network = false;

        if ($results_key && !empty($file_indices)) {
            // Prova prima site_transient (network), poi transient (single site)
            $results = get_site_transient("mui_results_$results_key");
            if ($results) {
                $is_network = true;
            } else {
                $results = get_transient("mui_results_$results_key");
            }
            
            if ($results && !empty($results['rows'])) {
                // Raggruppa i file per blog_id
                $files_by_blog = [];
                foreach ($file_indices as $idx) {
                    if (isset($results['rows'][$idx])) {
                        $row = $results['rows'][$idx];
                        $blog_id = isset($row['blog_id']) ? intval($row['blog_id']) : get_current_blog_id();
                        if (!isset($files_by_blog[$blog_id])) {
                            $files_by_blog[$blog_id] = [];
                        }
                        $files_by_blog[$blog_id][] = $row['fullpath'];
                    }
                }

                // Elimina i file, switchando al blog appropriato
                foreach ($files_by_blog as $bid => $files) {
                    if (is_multisite()) switch_to_blog($bid);
                    $this->setup_uploads_context();

                    foreach ($files as $full) {
                        // Per sicurezza, elimina solo se dentro uploads corrente
                        if (strpos($full, $this->uploads_dir) !== 0) continue;

                        // Cancella anche varianti -WxH
                        $deleted += $this->delete_with_variants($full, $errors);
                        // Prova a ripulire cartelle vuote
                        $this->cleanup_empty_dirs(dirname($full));
                    }

                    if (is_multisite()) restore_current_blog();
                }
            }
        }

        // Redirect appropriato
        $referer = wp_get_referer();
        if ($referer) {
            $base = $referer;
		} elseif ($is_network && is_multisite()) {
            $base = network_admin_url('admin.php?page=' . $this->page_slug . '-network');
        } else {
			$base = admin_url('admin.php?page=' . $this->page_slug);
        }

        $msg = add_query_arg([
            'mui_del' => 1,
            'mui_deleted' => $deleted,
            'mui_errors'  => $errors
        ], $base);

        wp_safe_redirect($msg);
        exit;
    }

    private function delete_with_variants($full, &$errors) {
        $count = 0;
        if (@is_file($full)) {
            if (@unlink($full)) $count++; else $errors++;
        }
        // Varianti -WxH nel medesimo folder
        $pi = pathinfo($full);
        $dir = $pi['dirname'];
        $name = $pi['filename'];
        $ext = isset($pi['extension']) ? '.' . $pi['extension'] : '';
        $pattern = '#^' . preg_quote($name, '#') . '-\d+x\d+' . preg_quote($ext, '#') . '$#';

        $dh = @opendir($dir);
        if ($dh) {
            while (($file = readdir($dh)) !== false) {
                if ($file === '.' || $file === '..') continue;
                if (preg_match($pattern, $file)) {
                    $p = wp_normalize_path($dir . '/' . $file);
                    if (@is_file($p)) {
                        if (@unlink($p)) $count++; else $errors++;
                    }
                }
            }
            closedir($dh);
        }
        return $count;
    }

    private function cleanup_empty_dirs($dir) {
        $dir = wp_normalize_path($dir);
        $uploads = $this->uploads_dir;

        while ($dir && strpos($dir, $uploads) === 0 && $dir !== $uploads) {
            $is_empty = true;
            $dh = @opendir($dir);
            if ($dh) {
                while (($f = readdir($dh)) !== false) {
                    if ($f === '.' || $f === '..') continue;
                    $is_empty = false; break;
                }
                closedir($dh);
            }
            if ($is_empty) {
                @rmdir($dir);
                $dir = wp_normalize_path(dirname($dir));
            } else {
                break;
            }
        }
    }
}

new Media_Usage_Inspector();

}
