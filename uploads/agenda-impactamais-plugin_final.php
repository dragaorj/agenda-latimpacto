<?php
/*
 * Plugin Name: Agenda Impacta Mais 2026
 * Plugin URI:  https://impactamais.com
 * Description: REST API para a agenda do evento com banco de dados dedicado e fotos na biblioteca de mídia.
 * Version:     6.0
 * Author:      Impacta Mais
 * License:     GPL2
 */

defined( 'ABSPATH' ) or die( 'No direct access.' );

define( 'AGIM_TOKEN_KEY', 'agenda_impactamais_secret_token' );
define( 'AGIM_DB_VERSION', '4' );
define( 'AGIM_PHOTO_MAX_BYTES', 5 * 1024 * 1024 ); // 5 MB
define( 'AGIM_PHOTO_DIR', 'agenda-impactamais' );  // subdir em wp-content/uploads

/* =========================================================
   ATIVAÇÃO — cria tabelas no banco
   ========================================================= */
register_activation_hook( __FILE__, 'agim_activate' );

function agim_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Tabela de palestrantes
    // NOTA: photo agora guarda URL (varchar curto). LONGTEXT mantido só para compat
    // com instalações antigas — a migração converte automaticamente.
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agim_speakers (
        id VARCHAR(64) NOT NULL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        role VARCHAR(255) DEFAULT '',
        company VARCHAR(255) DEFAULT '',
        bio TEXT DEFAULT '',
        photo LONGTEXT DEFAULT '',
        photo_attachment_id BIGINT UNSIGNED DEFAULT NULL,
        linkedin VARCHAR(500) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_name (name)
    ) $charset" );

    // Garante colunas em instalações já existentes
    $wpdb->query( "ALTER TABLE {$wpdb->prefix}agim_speakers ADD COLUMN IF NOT EXISTS linkedin VARCHAR(500) DEFAULT ''" );
    $wpdb->query( "ALTER TABLE {$wpdb->prefix}agim_speakers ADD COLUMN IF NOT EXISTS photo_attachment_id BIGINT UNSIGNED DEFAULT NULL" );

    // Tabela de palestras
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agim_talks (
        id VARCHAR(64) NOT NULL PRIMARY KEY,
        title VARCHAR(500) NOT NULL,
        day TINYINT NOT NULL DEFAULT 0,
        time_slot VARCHAR(10) NOT NULL DEFAULT '08:00',
        room_id VARCHAR(20) NOT NULL DEFAULT 'r1',
        description TEXT DEFAULT '',
        hub VARCHAR(10) DEFAULT '',
        format VARCHAR(20) DEFAULT '',
        libras TINYINT(1) DEFAULT 0,
        traducao TINYINT(1) DEFAULT 0,
        speaker_ids TEXT DEFAULT '',
        speaker_name VARCHAR(500) DEFAULT '',
        extra_data TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_day_time (day, time_slot)
    ) $charset" );

    // Tabela de slots customizados
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agim_slots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(10) NOT NULL UNIQUE,
        is_break TINYINT(1) DEFAULT 0,
        sort_order INT DEFAULT 0,
        INDEX idx_sort (sort_order)
    ) $charset" );

    // Tabela de configurações (hubs, rooms, etc.)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agim_config (
        config_key VARCHAR(64) NOT NULL PRIMARY KEY,
        config_value LONGTEXT DEFAULT ''
    ) $charset" );

    update_option( 'agim_db_version', AGIM_DB_VERSION );
}

function agim_maybe_upgrade_db() {
    if ( get_option( 'agim_db_version' ) !== AGIM_DB_VERSION ) {
        agim_activate();
    }
}

/* =========================================================
   FIX APACHE AUTH
   ========================================================= */
add_filter( 'determine_current_user', 'agim_fix_basic_auth', 20 );
function agim_fix_basic_auth( $user ) {
    if ( ! empty( $user ) ) return $user;
    $auth = '';
    if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) )               $auth = $_SERVER['HTTP_AUTHORIZATION'];
    elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) )  $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    elseif ( function_exists( 'apache_request_headers' ) ) {
        $h = apache_request_headers();
        if ( isset( $h['Authorization'] ) ) $auth = $h['Authorization'];
    }
    if ( empty( $auth ) || stripos( $auth, 'Basic ' ) !== 0 ) return $user;
    $parts = explode( ':', base64_decode( substr( $auth, 6 ) ), 2 );
    if ( count( $parts ) !== 2 ) return $user;
    $wp_user = get_user_by( 'login', $parts[0] );
    if ( $wp_user && wp_check_password( $parts[1], $wp_user->user_pass, $wp_user->ID ) )
        return $wp_user->ID;
    return $user;
}

/* =========================================================
   REST ROUTES
   ========================================================= */
add_action( 'rest_api_init', 'agim_register_routes' );
function agim_register_routes() {
    register_rest_route( 'agenda/v1', '/load',         [ 'methods' => 'GET',    'callback' => 'agim_load',         'permission_callback' => '__return_true' ] );
    register_rest_route( 'agenda/v1', '/save',         [ 'methods' => 'POST',   'callback' => 'agim_save',         'permission_callback' => 'agim_check_auth' ] );
    register_rest_route( 'agenda/v1', '/clear',        [ 'methods' => 'DELETE', 'callback' => 'agim_clear',        'permission_callback' => 'agim_check_auth' ] );
    register_rest_route( 'agenda/v1', '/token',        [ 'methods' => 'GET',    'callback' => 'agim_get_token',    'permission_callback' => 'agim_check_wp_logged' ] );
    register_rest_route( 'agenda/v1', '/upload-photo', [ 'methods' => 'POST',   'callback' => 'agim_upload_photo', 'permission_callback' => 'agim_check_auth' ] );
    register_rest_route( 'agenda/v1', '/migrate-photos', [ 'methods' => 'POST',   'callback' => 'agim_migrate_photos_endpoint', 'permission_callback' => 'agim_check_wp_logged' ] );
}

function agim_check_auth( $request ) {
    $saved = get_option( AGIM_TOKEN_KEY, '' );

    // Header padrão: X-Agenda-Token
    $token = $request->get_header( 'X-Agenda-Token' );

    // Fallback: token no body como _token (necessário para navigator.sendBeacon,
    // que não permite headers customizados). Aceitamos via JSON body ou query string.
    if ( empty( $token ) ) {
        $body_params = $request->get_json_params();
        if ( is_array( $body_params ) && ! empty( $body_params['_token'] ) ) {
            $token = $body_params['_token'];
        }
    }
    if ( empty( $token ) ) {
        $token = $request->get_param( '_token' );
    }

    if ( ! empty( $token ) && ! empty( $saved ) && hash_equals( $saved, $token ) ) return true;
    if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) return true;
    return new WP_Error( 'rest_forbidden', 'Autenticação necessária.', [ 'status' => 401 ] );
}
function agim_check_wp_logged() {
    if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) return true;
    return new WP_Error( 'rest_forbidden', 'Login necessário.', [ 'status' => 401 ] );
}

/* =========================================================
   FOTOS — utilitários para biblioteca de mídia
   ========================================================= */

/**
 * Detecta se uma string é uma data URL base64 (data:image/...;base64,XXX).
 */
function agim_is_base64_image( $str ) {
    return is_string( $str ) && strncmp( $str, 'data:image/', 11 ) === 0 && strpos( $str, ';base64,' ) !== false;
}

/**
 * Detecta se uma string é uma URL HTTP/HTTPS.
 */
function agim_is_url( $str ) {
    return is_string( $str ) && ( strncmp( $str, 'http://', 7 ) === 0 || strncmp( $str, 'https://', 8 ) === 0 );
}

/**
 * Converte uma data URL base64 em arquivo na biblioteca de mídia.
 * Retorna ['url' => string, 'attachment_id' => int] em caso de sucesso,
 * ou WP_Error em caso de falha.
 *
 * @param string $data_url      A data URL (data:image/png;base64,...)
 * @param string $speaker_name  Nome do palestrante (usado no nome do arquivo)
 * @param string $speaker_id    ID do palestrante (usado no nome do arquivo)
 */
function agim_save_base64_to_media( $data_url, $speaker_name = '', $speaker_id = '' ) {
    if ( ! agim_is_base64_image( $data_url ) ) {
        return new WP_Error( 'invalid_image', 'Formato de imagem inválido.' );
    }

    // Extrai mime type e dados
    if ( ! preg_match( '#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $data_url, $m ) ) {
        return new WP_Error( 'invalid_image', 'Não foi possível parsear a data URL.' );
    }
    $mime    = strtolower( $m[1] );
    $b64     = $m[2];
    // Libera memória da data URL original (pode ser grande)
    unset( $data_url, $m );

    // Mime types aceitos
    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if ( ! isset( $allowed_mimes[ $mime ] ) ) {
        return new WP_Error( 'invalid_mime', 'Tipo de imagem não permitido: ' . $mime );
    }
    $ext = $allowed_mimes[ $mime ];

    // Decodifica em memória
    $binary = base64_decode( $b64, true );
    unset( $b64 );
    if ( $binary === false ) {
        return new WP_Error( 'invalid_base64', 'Base64 inválido.' );
    }

    $size = strlen( $binary );
    if ( $size > AGIM_PHOTO_MAX_BYTES ) {
        unset( $binary );
        return new WP_Error( 'too_large', 'Imagem maior que ' . ( AGIM_PHOTO_MAX_BYTES / 1024 / 1024 ) . 'MB.' );
    }

    // Garante que as funções de upload do WordPress estão disponíveis
    if ( ! function_exists( 'wp_handle_sideload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if ( ! function_exists( 'wp_insert_attachment' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }

    // Monta nome do arquivo
    $slug_source = $speaker_name ?: $speaker_id ?: 'photo';
    $slug        = sanitize_title( $slug_source );
    if ( empty( $slug ) ) $slug = 'photo';
    $filename    = $slug . '-' . substr( md5( $binary . microtime() ), 0, 8 ) . '.' . $ext;

    // Cria subdiretório dedicado em wp-content/uploads
    $upload_dir = wp_upload_dir();
    if ( ! empty( $upload_dir['error'] ) ) {
        unset( $binary );
        return new WP_Error( 'upload_dir', 'wp_upload_dir error: ' . $upload_dir['error'] );
    }
    $target_dir = trailingslashit( $upload_dir['path'] ) . AGIM_PHOTO_DIR;
    if ( ! file_exists( $target_dir ) ) {
        if ( ! wp_mkdir_p( $target_dir ) ) {
            unset( $binary );
            return new WP_Error( 'mkdir_failed', 'Falha ao criar diretório: ' . $target_dir );
        }
    }
    // Garante que é gravável
    if ( ! is_writable( $target_dir ) ) {
        unset( $binary );
        return new WP_Error( 'dir_not_writable', 'Diretório sem permissão de escrita: ' . $target_dir );
    }
    $target_path = $target_dir . '/' . $filename;
    $target_url  = trailingslashit( $upload_dir['url'] ) . AGIM_PHOTO_DIR . '/' . $filename;

    // Escreve arquivo no disco e libera memória do binário imediatamente
    $written = file_put_contents( $target_path, $binary );
    unset( $binary );
    if ( $written === false || $written === 0 ) {
        return new WP_Error( 'write_failed', 'Falha ao gravar o arquivo em: ' . $target_path );
    }

    // ───────────────────────────────────────────────────────────
    // FORÇA mime types permitidos durante o insert (alguns hosts
    // bloqueiam webp e até jpg/png personalizados via filter).
    // O filter é temporário: ativado só para esta inserção.
    // ───────────────────────────────────────────────────────────
    $allow_mimes_filter = function( $mimes ) {
        $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
        $mimes['png']         = 'image/png';
        $mimes['gif']         = 'image/gif';
        $mimes['webp']        = 'image/webp';
        return $mimes;
    };
    $check_filetype_filter = function( $data, $file, $filename, $mimes ) use ( $mime, $ext ) {
        // Se o WP rejeitou o tipo, mas é um dos nossos confiáveis, força aceitar.
        if ( empty( $data['ext'] ) || empty( $data['type'] ) ) {
            if ( in_array( $ext, [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ], true ) ) {
                $data['ext']             = $ext === 'jpeg' ? 'jpg' : $ext;
                $data['type']            = $mime;
                $data['proper_filename'] = $filename;
            }
        }
        return $data;
    };

    add_filter( 'upload_mimes', $allow_mimes_filter );
    add_filter( 'wp_check_filetype_and_ext', $check_filetype_filter, 10, 4 );

    // Cria o attachment no WordPress
    $attachment = [
        'guid'           => $target_url,
        'post_mime_type' => $mime,
        'post_title'     => $speaker_name ?: $slug,
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    $attach_id = wp_insert_attachment( $attachment, $target_path );

    // Gera metadados (thumbnails) — IMPORTANTE: isso pode consumir memória,
    // mas roda apenas no upload, não em todo /load
    $metadata_error = null;
    if ( ! is_wp_error( $attach_id ) && $attach_id ) {
        try {
            $metadata = wp_generate_attachment_metadata( $attach_id, $target_path );
            wp_update_attachment_metadata( $attach_id, $metadata );
        } catch ( Throwable $e ) {
            // Thumbnails falharam mas o arquivo principal já está salvo.
            // Não é fatal — segue em frente, a imagem original funciona.
            $metadata_error = $e->getMessage();
        }
    }

    // Remove os filtros temporários
    remove_filter( 'upload_mimes', $allow_mimes_filter );
    remove_filter( 'wp_check_filetype_and_ext', $check_filetype_filter, 10 );

    if ( is_wp_error( $attach_id ) ) {
        @unlink( $target_path );
        return new WP_Error( 'attachment_failed', 'wp_insert_attachment: ' . $attach_id->get_error_message() );
    }
    if ( ! $attach_id ) {
        @unlink( $target_path );
        return new WP_Error( 'attachment_failed', 'wp_insert_attachment retornou 0 — provavelmente tipo de arquivo bloqueado.' );
    }

    return [
        'url'           => $target_url,
        'attachment_id' => (int) $attach_id,
        'metadata_warning' => $metadata_error,
    ];
}

/* =========================================================
   ENDPOINT — upload de foto separado
   POST /agenda/v1/upload-photo
   Body JSON: { speakerId, speakerName, photo (base64 data URL) }
   OU multipart com campo 'photo' (arquivo) e 'speakerId', 'speakerName'
   ========================================================= */
function agim_upload_photo( $request ) {
    agim_cors_headers();

    $speaker_id   = sanitize_text_field( $request->get_param( 'speakerId' ) ?? '' );
    $speaker_name = sanitize_text_field( $request->get_param( 'speakerName' ) ?? '' );

    // Caso 1: upload multipart (arquivo)
    $files = $request->get_file_params();
    if ( ! empty( $files['photo'] ) && ! empty( $files['photo']['tmp_name'] ) ) {
        $file = $files['photo'];

        if ( $file['size'] > AGIM_PHOTO_MAX_BYTES ) {
            return new WP_Error( 'too_large', 'Imagem maior que ' . ( AGIM_PHOTO_MAX_BYTES / 1024 / 1024 ) . 'MB.', [ 'status' => 413 ] );
        }

        if ( ! function_exists( 'wp_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_insert_attachment' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $overrides = [ 'test_form' => false ];
        $sideload  = wp_handle_sideload( $file, $overrides );
        if ( ! empty( $sideload['error'] ) ) {
            return new WP_Error( 'upload_failed', $sideload['error'], [ 'status' => 400 ] );
        }

        $attachment = [
            'guid'           => $sideload['url'],
            'post_mime_type' => $sideload['type'],
            'post_title'     => $speaker_name ?: pathinfo( $sideload['file'], PATHINFO_FILENAME ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment( $attachment, $sideload['file'] );
        if ( is_wp_error( $attach_id ) || ! $attach_id ) {
            @unlink( $sideload['file'] );
            return new WP_Error( 'attachment_failed', 'Falha ao registrar o anexo.', [ 'status' => 500 ] );
        }
        $metadata = wp_generate_attachment_metadata( $attach_id, $sideload['file'] );
        wp_update_attachment_metadata( $attach_id, $metadata );

        return rest_ensure_response( [
            'success'       => true,
            'url'           => $sideload['url'],
            'attachment_id' => (int) $attach_id,
        ] );
    }

    // Caso 2: JSON com base64
    $body  = $request->get_json_params();
    $photo = $body['photo'] ?? null;
    unset( $body );

    if ( empty( $photo ) ) {
        return new WP_Error( 'no_photo', 'Nenhuma foto enviada.', [ 'status' => 400 ] );
    }

    // Se já for uma URL, retorna ela mesma (idempotente)
    if ( agim_is_url( $photo ) ) {
        return rest_ensure_response( [
            'success'       => true,
            'url'           => esc_url_raw( $photo ),
            'attachment_id' => null,
            'note'          => 'Já era URL, nenhum upload necessário.',
        ] );
    }

    $result = agim_save_base64_to_media( $photo, $speaker_name, $speaker_id );
    unset( $photo );

    if ( is_wp_error( $result ) ) {
        return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 400 ] );
    }

    return rest_ensure_response( [
        'success'       => true,
        'url'           => $result['url'],
        'attachment_id' => $result['attachment_id'],
    ] );
}

/* =========================================================
   MIGRAÇÃO — converte fotos base64 antigas em arquivos
   Endpoint manual: POST /agenda/v1/migrate-photos (requer login)
   Processa em lote (limit) para não estourar memória.
   ========================================================= */
function agim_migrate_photos_endpoint( $request ) {
    $batch = max( 1, min( 50, (int) ( $request->get_param( 'batch' ) ?? 5 ) ) );
    $result = agim_migrate_photos_batch( $batch );
    return rest_ensure_response( $result );
}

function agim_migrate_photos_batch( $limit = 5 ) {
    global $wpdb;
    $prefix = $wpdb->prefix;

    // Busca apenas linhas que ainda têm base64 — sem carregar a foto inteira no SELECT do count
    $pending = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$prefix}agim_speakers
         WHERE photo_attachment_id IS NULL AND photo LIKE 'data:image/%'"
    );

    if ( $pending === 0 ) {
        return [ 'success' => true, 'migrated' => 0, 'remaining' => 0, 'done' => true ];
    }

    // Pega só os IDs primeiro (sem carregar o base64)
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM {$prefix}agim_speakers
         WHERE photo_attachment_id IS NULL AND photo LIKE 'data:image/%'
         ORDER BY name ASC
         LIMIT %d",
        $limit
    ) );

    $migrated = 0;
    $errors   = [];

    foreach ( $ids as $speaker_id ) {
        // Pega só a foto e o nome desse palestrante específico
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, photo FROM {$prefix}agim_speakers WHERE id = %s",
            $speaker_id
        ), ARRAY_A );

        if ( ! $row || ! agim_is_base64_image( $row['photo'] ) ) continue;

        $result = agim_save_base64_to_media( $row['photo'], $row['name'], $row['id'] );
        // Libera memória do row (que continha o base64)
        $name = $row['name'];
        unset( $row );

        if ( is_wp_error( $result ) ) {
            $errors[] = [ 'id' => $speaker_id, 'name' => $name, 'error' => $result->get_error_message() ];
            continue;
        }

        $wpdb->update(
            "{$prefix}agim_speakers",
            [
                'photo'               => $result['url'],
                'photo_attachment_id' => $result['attachment_id'],
            ],
            [ 'id' => $speaker_id ],
            [ '%s', '%d' ],
            [ '%s' ]
        );
        $migrated++;
    }

    $remaining = $pending - $migrated;
    return [
        'success'   => true,
        'migrated'  => $migrated,
        'remaining' => max( 0, $remaining ),
        'errors'    => $errors,
        'done'      => $remaining <= 0,
    ];
}

/* =========================================================
   LOAD — lê do banco e monta estrutura para o frontend
   ========================================================= */
function agim_load( $request ) {
    agim_cors_headers();
    global $wpdb;

    agim_maybe_upgrade_db();

    $prefix = $wpdb->prefix;

    // Palestrantes — SELECT explícito
    $speaker_rows = $wpdb->get_results(
        "SELECT id, name, role, company, bio, photo, linkedin
         FROM {$prefix}agim_speakers
         ORDER BY name ASC",
        ARRAY_A
    );
    $speakers = [];
    if ( $speaker_rows ) {
        foreach ( $speaker_rows as $row ) {
            $speakers[] = [
                'id'       => $row['id'],
                'name'     => $row['name'],
                'role'     => $row['role'],
                'company'  => $row['company'],
                'bio'      => $row['bio'],
                'photo'    => $row['photo'], // pode ser URL (novo) ou base64 (legado, até migrar)
                'linkedin' => $row['linkedin'] ?? '',
            ];
        }
        unset( $speaker_rows );
    }

    // Palestras
    $talk_rows = $wpdb->get_results(
        "SELECT id, title, day, time_slot, room_id, description, hub, format,
                libras, traducao, speaker_ids, speaker_name, extra_data
         FROM {$prefix}agim_talks
         ORDER BY time_slot ASC",
        ARRAY_A
    );

    $schedules = [ [], [] ];
    $talk_count = 0;
    if ( $talk_rows ) {
        foreach ( $talk_rows as $row ) {
            $d = (int) $row['day'];
            if ( $d < 0 || $d > 1 ) continue;

            $speaker_ids = $row['speaker_ids'] ? json_decode( $row['speaker_ids'], true ) : [];
            if ( ! is_array( $speaker_ids ) ) $speaker_ids = [];

            $duration = 30;
            if ( ! empty( $row['extra_data'] ) && $row['extra_data'] !== '{}' ) {
                $extra = json_decode( $row['extra_data'], true );
                if ( is_array( $extra ) && isset( $extra['duration'] ) ) {
                    $dur = (int) $extra['duration'];
                    if ( $dur > 0 ) $duration = $dur;
                }
            }

            $talk = [
                'id'         => $row['id'],
                'title'      => $row['title'],
                'day'        => $d,
                'time'       => $row['time_slot'],
                'room'       => $row['room_id'],
                'desc'       => $row['description'],
                'hub'        => $row['hub'],
                'format'     => $row['format'],
                'libras'     => (bool) $row['libras'],
                'traducao'   => (bool) $row['traducao'],
                'speakerIds' => $speaker_ids,
                'speakerId'  => $speaker_ids[0] ?? null,
                'speakerName'=> $row['speaker_name'],
                'duration'   => $duration,
            ];

            $t = $row['time_slot'];
            $r = $row['room_id'];
            if ( ! isset( $schedules[$d][$t] ) ) $schedules[$d][$t] = [];
            $schedules[$d][$t][$r] = $talk;
        }
        $talk_count = count( $talk_rows );
        unset( $talk_rows );
    }

    // Slots customizados
    $slot_rows = $wpdb->get_results(
        "SELECT label, is_break FROM {$prefix}agim_slots ORDER BY sort_order ASC",
        ARRAY_A
    );
    $slots = [];
    if ( $slot_rows ) {
        foreach ( $slot_rows as $r ) {
            $slots[] = [ 'label' => $r['label'], 'isBreak' => (bool) $r['is_break'] ];
        }
        unset( $slot_rows );
    }

    // Hubs, hubColors e rooms — UMA query
    $config_rows = $wpdb->get_results(
        "SELECT config_key, config_value FROM {$prefix}agim_config
         WHERE config_key IN ('hubs','hubColors','rooms')",
        ARRAY_A
    );
    $hubs = $hub_colors = $rooms = null;
    if ( $config_rows ) {
        foreach ( $config_rows as $cfg ) {
            $decoded = $cfg['config_value'] ? json_decode( $cfg['config_value'], true ) : null;
            if ( $cfg['config_key'] === 'hubs' )            $hubs       = $decoded;
            elseif ( $cfg['config_key'] === 'hubColors' )   $hub_colors = $decoded;
            elseif ( $cfg['config_key'] === 'rooms' )       $rooms      = $decoded;
        }
        unset( $config_rows );
    }

    $data = [
        'speakers'  => $speakers,
        'schedules' => $schedules,
        'slots'     => $slots,
    ];
    // IMPORTANTE: incluímos hubs/hubColors/rooms na resposta sempre que foram
    // gravados no banco (mesmo que sejam arrays vazios). O frontend trata a
    // PRESENÇA do campo como sinal de "servidor é autoritativo" — sem isso,
    // ao apagar a última sala/hub o frontend reverte para os defaults hardcoded.
    if ( is_array( $hubs ) )       $data['hubs']      = $hubs;
    if ( is_array( $hub_colors ) ) $data['hubColors'] = $hub_colors;
    if ( is_array( $rooms ) )      $data['rooms']     = $rooms;

    return rest_ensure_response( [
        'success'    => true,
        'data'       => $data,
        'debug'      => [
            'speakers' => count( $speakers ),
            'talks'    => $talk_count,
            'slots'    => count( $slots ),
            'hubs'     => is_array( $hubs ) ? count( $hubs ) : 0,
            'rooms'    => is_array( $rooms ) ? count( $rooms ) : 0,
        ],
        'updated_at' => current_time( 'mysql' ),
    ] );
}

/* =========================================================
   SAVE — aceita photo como URL OU base64 (converte para mídia)

   FLUXO (importante para confiabilidade):
   1) FASE 1: processa todas as fotos base64 FORA da transação SQL.
      Cada foto é convertida para arquivo na biblioteca de mídia.
      Se uma foto falhar (memória, GD, disco), o palestrante é salvo
      com a foto antiga preservada — nunca perdemos o registro do
      palestrante por causa da foto.
   2) FASE 2: transação SQL curta, só com operações leves (INSERT/UPDATE
      de texto). Nada de processamento de imagem aqui — evita estourar
      memory_limit no meio da transação, o que causaria rollback e perda
      de palestrantes recém-criados.
   ========================================================= */
function agim_save( $request ) {
    global $wpdb;
    agim_maybe_upgrade_db();

    $body = $request->get_json_params();
    if ( empty( $body ) )
        return new WP_Error( 'empty_body', 'Body vazio.', [ 'status' => 400 ] );

    $speakers   = isset( $body['speakers'] )   ? $body['speakers']   : [];
    $schedules  = isset( $body['schedules'] )  ? $body['schedules']  : [];
    $slots      = isset( $body['slots'] )      ? $body['slots']      : [];
    $hubs       = isset( $body['hubs'] )       ? $body['hubs']       : null;
    $hub_colors = isset( $body['hubColors'] )  ? $body['hubColors']  : null;
    $rooms      = isset( $body['rooms'] )      ? $body['rooms']      : null;

    unset( $body );

    $prefix = $wpdb->prefix;
    $photo_errors = [];

    // ─────────────────────────────────────────────────────────
    // FASE 1: processa fotos base64 FORA da transação.
    // Para cada palestrante, decide qual URL será gravada no banco.
    // Resultados ficam em $resolved_speakers, indexado por id.
    // ─────────────────────────────────────────────────────────

    // Pede mais memória ao WP especificamente para processamento de imagens
    if ( function_exists( 'wp_raise_memory_limit' ) ) {
        wp_raise_memory_limit( 'image' );
    }

    $resolved_speakers = [];

    foreach ( (array) $speakers as $i => $spk ) {
        if ( empty( $spk['id'] ) ) continue;

        $spk_id   = sanitize_text_field( $spk['id'] );
        $spk_name = sanitize_text_field( $spk['name'] ?? '' );
        $photo_in = $spk['photo'] ?? '';
        $photo_url = '';
        $photo_attach_id = null;

        if ( agim_is_base64_image( $photo_in ) ) {
            // Tenta converter base64 → arquivo. Captura QUALQUER exceção
            // ou erro para não derrubar o save inteiro.
            $result = null;
            try {
                $result = agim_save_base64_to_media( $photo_in, $spk_name, $spk_id );
            } catch ( Throwable $e ) {
                $result = new WP_Error( 'exception', $e->getMessage() );
            }

            if ( is_wp_error( $result ) ) {
                // Foto falhou — preserva a que já estava no banco (se existir)
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT photo FROM {$prefix}agim_speakers WHERE id = %s",
                    $spk_id
                ) );
                $photo_url       = $existing ?: '';
                $photo_attach_id = null; // não mexe no attachment antigo
                $err_msg = $result->get_error_code() . ': ' . $result->get_error_message();
                $photo_errors[]  = [ 'id' => $spk_id, 'name' => $spk_name, 'error' => $err_msg ];
                // Também loga no error_log do PHP/WP para debug do servidor
                error_log( '[AgendaImpactaMais] Foto falhou para palestrante "' . $spk_name . '" (' . $spk_id . '): ' . $err_msg );
            } else {
                $photo_url       = $result['url'];
                $photo_attach_id = $result['attachment_id'];
            }
        } elseif ( agim_is_url( $photo_in ) ) {
            $photo_url = esc_url_raw( $photo_in );
        } elseif ( empty( $photo_in ) ) {
            $photo_url = '';
        } else {
            $photo_url = $photo_in;
        }

        // Libera memória do base64 original
        unset( $photo_in, $spk['photo'] );

        $resolved_speakers[ $spk_id ] = [
            'id'                  => $spk_id,
            'name'                => $spk_name,
            'role'                => sanitize_text_field( $spk['role'] ?? '' ),
            'company'             => sanitize_text_field( $spk['company'] ?? '' ),
            'bio'                 => sanitize_textarea_field( $spk['bio'] ?? '' ),
            'photo'               => $photo_url,
            'linkedin'            => sanitize_text_field( $spk['linkedin'] ?? '' ),
            'photo_attachment_id' => $photo_attach_id, // null se não mexer
        ];

        // Libera memória do speaker original
        unset( $speakers[ $i ], $spk );
    }
    // A esta altura $speakers só tem array vazio/lixo — esquecemos dele
    unset( $speakers );

    // ─────────────────────────────────────────────────────────
    // FASE 2: transação SQL — só queries leves, sem processamento de imagem
    // ─────────────────────────────────────────────────────────

    $wpdb->query( 'START TRANSACTION' );

    $saved_spk = 0;
    $kept_ids  = [];

    foreach ( $resolved_speakers as $spk_id => $row ) {
        $update_data = [
            'id'       => $row['id'],
            'name'     => $row['name'],
            'role'     => $row['role'],
            'company'  => $row['company'],
            'bio'      => $row['bio'],
            'photo'    => $row['photo'],
            'linkedin' => $row['linkedin'],
        ];
        // Só atualiza attachment_id se uma foto nova foi processada com sucesso.
        // Senão deixa o que está no banco (não vira NULL acidentalmente).
        if ( $row['photo_attachment_id'] !== null ) {
            $update_data['photo_attachment_id'] = $row['photo_attachment_id'];
        }

        $wpdb->replace( "{$prefix}agim_speakers", $update_data );
        $kept_ids[] = $spk_id;
        $saved_spk++;
    }
    unset( $resolved_speakers );

    // Apaga palestrantes que não vieram mais (apenas se a lista veio com algum item).
    // Importante: NUNCA apagar quando $kept_ids está vazio, porque pode ser
    // um save parcial (ex: usuário editando só schedules) — perder todos os
    // palestrantes nesse caso seria catastrófico.
    if ( ! empty( $kept_ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $kept_ids ), '%s' ) );

        // Pega attachments dos que serão deletados para limpeza pós-commit
        $to_delete_attach = $wpdb->get_col( $wpdb->prepare(
            "SELECT photo_attachment_id FROM {$prefix}agim_speakers
             WHERE id NOT IN ($placeholders) AND photo_attachment_id IS NOT NULL",
            ...$kept_ids
        ) );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$prefix}agim_speakers WHERE id NOT IN ($placeholders)",
            ...$kept_ids
        ) );
    } else {
        $to_delete_attach = [];
    }

    // Salva palestras
    $saved_talks = 0;
    $talk_ids = [];
    foreach ( (array) $schedules as $day_idx => $day ) {
        if ( ! is_array( $day ) ) continue;
        foreach ( $day as $slot => $rooms_day ) {
            if ( ! is_array( $rooms_day ) ) continue;
            foreach ( $rooms_day as $room_id => $talk ) {
                if ( empty( $talk['id'] ) ) continue;
                $extra = [];
                foreach ( [ 'speakerId', 'speakerIds', 'speakerName' ] as $k ) {
                    if ( isset( $talk[$k] ) ) $extra[$k] = $talk[$k];
                }
                if ( isset( $talk['duration'] ) && intval( $talk['duration'] ) > 0 ) {
                    $extra['duration'] = intval( $talk['duration'] );
                }
                $wpdb->replace( "{$prefix}agim_talks", [
                    'id'          => sanitize_text_field( $talk['id'] ),
                    'title'       => sanitize_text_field( $talk['title'] ?? '' ),
                    'day'         => intval( $day_idx ),
                    'time_slot'   => sanitize_text_field( $slot ),
                    'room_id'     => sanitize_text_field( $room_id ),
                    'description' => sanitize_textarea_field( $talk['desc'] ?? '' ),
                    'hub'         => sanitize_text_field( $talk['hub'] ?? '' ),
                    'format'      => sanitize_text_field( $talk['format'] ?? '' ),
                    'libras'      => empty( $talk['libras'] ) ? 0 : 1,
                    'traducao'    => empty( $talk['traducao'] ) ? 0 : 1,
                    'speaker_ids' => wp_json_encode( $talk['speakerIds'] ?? [] ),
                    'speaker_name'=> sanitize_text_field( $talk['speakerName'] ?? '' ),
                    'extra_data'  => wp_json_encode( $extra ),
                ] );
                $talk_ids[] = $talk['id'];
                $saved_talks++;
            }
        }
    }
    unset( $schedules );

    if ( ! empty( $talk_ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $talk_ids ), '%s' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$prefix}agim_talks WHERE id NOT IN ($placeholders)",
            ...$talk_ids
        ) );
    }

    if ( ! empty( $slots ) ) {
        $wpdb->query( "DELETE FROM {$prefix}agim_slots" );
        foreach ( (array) $slots as $i => $slot ) {
            if ( empty( $slot['label'] ) ) continue;
            $wpdb->insert( "{$prefix}agim_slots", [
                'label'      => sanitize_text_field( $slot['label'] ),
                'is_break'   => empty( $slot['isBreak'] ) ? 0 : 1,
                'sort_order' => $i,
            ] );
        }
    }

    if ( is_array( $hubs ) ) {
        $wpdb->replace( "{$prefix}agim_config", [
            'config_key'   => 'hubs',
            'config_value' => wp_json_encode( $hubs ),
        ] );
    }
    if ( is_array( $hub_colors ) ) {
        $wpdb->replace( "{$prefix}agim_config", [
            'config_key'   => 'hubColors',
            'config_value' => wp_json_encode( $hub_colors ),
        ] );
    }
    if ( is_array( $rooms ) ) {
        $wpdb->replace( "{$prefix}agim_config", [
            'config_key'   => 'rooms',
            'config_value' => wp_json_encode( $rooms ),
        ] );
    }

    $wpdb->query( 'COMMIT' );

    // Limpeza de attachments órfãos — sempre DEPOIS do commit.
    // wp_delete_attachment é caro e pode falhar; nunca dentro da transação.
    foreach ( $to_delete_attach as $att_id ) {
        if ( $att_id ) {
            try { wp_delete_attachment( (int) $att_id, true ); }
            catch ( Throwable $e ) { /* ignora erros de limpeza */ }
        }
    }

    $response = [
        'success'  => true,
        'message'  => 'Dados salvos.',
        'speakers' => $saved_spk,
        'talks'    => $saved_talks,
    ];
    if ( ! empty( $photo_errors ) ) {
        $response['photo_errors'] = $photo_errors;
    }
    return rest_ensure_response( $response );
}

/* =========================================================
   CLEAR — apaga dados (mantém anexos por segurança, a menos que force=1)
   ========================================================= */
function agim_clear( $request ) {
    global $wpdb;
    $prefix = $wpdb->prefix;
    $force  = (bool) ( $request->get_param( 'force_media' ) ?? false );

    if ( $force ) {
        $attach_ids = $wpdb->get_col(
            "SELECT photo_attachment_id FROM {$prefix}agim_speakers WHERE photo_attachment_id IS NOT NULL"
        );
        foreach ( $attach_ids as $att_id ) {
            if ( $att_id ) wp_delete_attachment( (int) $att_id, true );
        }
    }

    $wpdb->query( "DELETE FROM {$prefix}agim_speakers" );
    $wpdb->query( "DELETE FROM {$prefix}agim_talks" );
    $wpdb->query( "DELETE FROM {$prefix}agim_slots" );
    $wpdb->query( "DELETE FROM {$prefix}agim_config WHERE config_key IN ('hubs','hubColors','rooms')" );
    return rest_ensure_response( [ 'success' => true, 'message' => 'Dados apagados.' ] );
}

/* =========================================================
   TOKEN
   ========================================================= */
function agim_get_token() {
    $token = get_option( AGIM_TOKEN_KEY, '' );
    if ( empty( $token ) ) {
        $token = wp_generate_password( 32, false );
        update_option( AGIM_TOKEN_KEY, $token, false );
    }
    return rest_ensure_response( [ 'success' => true, 'token' => $token ] );
}

/* =========================================================
   CORS
   ========================================================= */
function agim_cors_headers() {
    if ( headers_sent() ) return;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header( 'Access-Control-Allow-Origin: ' . $origin );
    header( 'Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS' );
    header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Agenda-Token' );
    header( 'Access-Control-Allow-Credentials: true' );
}
add_action( 'rest_api_init', 'agim_add_cors_filter', 15 );
function agim_add_cors_filter() {
    remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
    add_filter( 'rest_pre_serve_request', 'agim_serve_cors_filter' );
}
function agim_serve_cors_filter( $value ) {
    agim_cors_headers();
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'OPTIONS' ) { status_header( 200 ); exit(); }
    return $value;
}

/* =========================================================
   ADMIN PAGE — com botão de migração de fotos
   ========================================================= */
add_action( 'admin_menu', 'agim_admin_menu' );
function agim_admin_menu() {
    add_menu_page( 'Agenda Impacta Mais', 'Agenda Evento', 'edit_posts',
        'agenda-impactamais', 'agim_admin_page', 'dashicons-calendar-alt', 30 );
}

function agim_admin_page() {
    global $wpdb;
    agim_maybe_upgrade_db();
    $prefix = $wpdb->prefix;

    if ( isset( $_POST['agim_action'] ) && $_POST['agim_action'] === 'clear' && current_user_can( 'manage_options' ) ) {
        check_admin_referer( 'agim_clear_action' );
        $wpdb->query( "DELETE FROM {$prefix}agim_speakers" );
        $wpdb->query( "DELETE FROM {$prefix}agim_talks" );
        $wpdb->query( "DELETE FROM {$prefix}agim_slots" );
        echo '<div class="notice notice-success"><p>Dados apagados.</p></div>';
    }
    if ( isset( $_POST['agim_action'] ) && $_POST['agim_action'] === 'regen_token' && current_user_can( 'edit_posts' ) ) {
        check_admin_referer( 'agim_regen_token' );
        $token = wp_generate_password( 32, false );
        update_option( AGIM_TOKEN_KEY, $token, false );
        echo '<div class="notice notice-success"><p>Token regenerado.</p></div>';
    }
    if ( isset( $_POST['agim_action'] ) && $_POST['agim_action'] === 'migrate_photos' && current_user_can( 'edit_posts' ) ) {
        check_admin_referer( 'agim_migrate_photos' );
        $batch  = max( 1, min( 50, (int) ( $_POST['batch'] ?? 5 ) ) );
        $result = agim_migrate_photos_batch( $batch );
        echo '<div class="notice notice-success"><p>';
        echo 'Migradas: <b>' . intval( $result['migrated'] ) . '</b>. ';
        echo 'Restantes: <b>' . intval( $result['remaining'] ) . '</b>.';
        if ( ! empty( $result['errors'] ) ) {
            echo '<br>Erros: ' . count( $result['errors'] );
            foreach ( $result['errors'] as $err ) {
                echo '<br>&bull; ' . esc_html( $err['name'] ?? $err['id'] ) . ': ' . esc_html( $err['error'] );
            }
        }
        echo '</p></div>';
    }

    $n_spk   = $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}agim_speakers" );
    $n_talk  = $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}agim_talks" );
    $n_b64   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}agim_speakers WHERE photo LIKE 'data:image/%'" );
    $n_url   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}agim_speakers WHERE photo LIKE 'http%'" );
    $token   = get_option( AGIM_TOKEN_KEY, '' );
    $site    = get_site_url();
    $ep      = get_rest_url( null, 'agenda/v1' );

    echo '<div class="wrap"><h1>&#128197; Agenda Impacta Mais 2026 <span style="font-size:13px;color:#666;font-weight:400">v6.0 — biblioteca de mídia</span></h1>';

    echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;">';
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px 24px;text-align:center;"><div style="font-size:28px;font-weight:700;color:#1a73e8;">' . intval( $n_spk ) . '</div><div>Palestrantes</div></div>';
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px 24px;text-align:center;"><div style="font-size:28px;font-weight:700;color:#0f9d58;">' . intval( $n_talk ) . '</div><div>Palestras</div></div>';
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px 24px;text-align:center;"><div style="font-size:28px;font-weight:700;color:' . ( $n_b64 > 0 ? '#d63638' : '#0f9d58' ) . ';">' . $n_b64 . '</div><div>Fotos base64 (legado)</div></div>';
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px 24px;text-align:center;"><div style="font-size:28px;font-weight:700;color:#0f9d58;">' . $n_url . '</div><div>Fotos em mídia</div></div>';
    echo '</div>';

    // Bloco de migração de fotos
    if ( $n_b64 > 0 ) {
        echo '<div style="background:#fff8e1;border:1px solid #f9a825;border-radius:8px;padding:16px 20px;margin-bottom:20px;max-width:700px;">';
        echo '<b>&#9888; Migração de fotos pendente</b><br>';
        echo '<p>Existem <b>' . $n_b64 . '</b> palestrantes com foto em base64 no banco. Migre para a biblioteca de mídia para reduzir o consumo de memória do <code>/load</code>.</p>';
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field( 'agim_migrate_photos' );
        echo '<input type="hidden" name="agim_action" value="migrate_photos">';
        echo '<label>Lote: <input type="number" name="batch" value="5" min="1" max="50" style="width:60px;"></label> ';
        echo '<button type="submit" class="button button-primary">Migrar próximo lote</button>';
        echo '<span style="color:#666;margin-left:10px;font-size:12px;">Recomendado: 5 por vez para fotos grandes</span>';
        echo '</form></div>';
    }

    echo '<div style="background:#e8f0fe;border:1px solid #1a73e8;border-radius:8px;padding:16px 20px;margin-bottom:20px;max-width:700px;">';
    echo '<b>&#128273; Token de Acesso</b><br><br>';
    if ( $token ) echo '<code style="background:#fff;padding:8px 12px;border-radius:4px;font-size:14px;display:block;word-break:break-all;margin-bottom:10px;">' . esc_html( $token ) . '</code>';
    echo '<form method="post" style="display:inline;">'; wp_nonce_field( 'agim_regen_token' );
    echo '<input type="hidden" name="agim_action" value="regen_token">';
    echo '<button type="submit" class="button button-primary">' . ( $token ? 'Regenerar Token' : 'Gerar Token' ) . '</button></form></div>';

    echo '<table class="widefat" style="max-width:700px;margin-bottom:20px;">';
    echo '<tr><th>URL do WordPress</th><td><code>' . esc_html( $site ) . '</code></td></tr>';
    echo '<tr><th>Endpoint público</th><td><code>' . esc_html( $ep ) . '/load</code></td></tr>';
    echo '<tr><th>Upload de foto</th><td><code>' . esc_html( $ep ) . '/upload-photo</code></td></tr>';
    echo '<tr><th>Migrar fotos</th><td><code>' . esc_html( $ep ) . '/migrate-photos</code></td></tr>';
    echo '</table>';

    echo '<form method="post" onsubmit="return confirm(\'Apagar todos os dados?\');">';
    wp_nonce_field( 'agim_clear_action' );
    echo '<input type="hidden" name="agim_action" value="clear">';
    echo '<button type="submit" class="button" style="color:#d63638;border-color:#d63638;">&#128465; Apagar todos os dados</button>';
    echo '</form></div>';
}
