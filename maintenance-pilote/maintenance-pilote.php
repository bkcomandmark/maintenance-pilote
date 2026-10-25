<?php
/**
 * Plugin Name:       Maintenance Pilote
 * Description:       Actions de maintenance et de publication contrôlées (état des mises à jour, mise à jour élément par élément avec copie de sécurité, retour arrière, vérification du site) exposées à Claude via l'API Abilities de WordPress.
 * Version:           2.4.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            BK COM AND MARK
 * Update URI:        https://github.com/bkcomandmark/maintenance-pilote
 * License:           GPL-2.0-or-later
 * Text Domain:       maintenance-pilote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MP_VERSION', '2.4.1' );
define( 'MP_SNAP_DIR', WP_CONTENT_DIR . '/maintenance-pilote-snapshots' );
define( 'MP_SNAP_KEEP', 3 );
define( 'MP_GITHUB_REPO', 'bkcomandmark/maintenance-pilote' );

/* -------------------------------------------------------------------------
 * Outils internes
 * ---------------------------------------------------------------------- */

function mp_load_admin() {
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

function mp_prepare() {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 600 );
	}
	wp_raise_memory_limit( 'admin' );
	mp_load_admin();
}

function mp_fs() {
	mp_load_admin();
	global $wp_filesystem;
	$method = get_filesystem_method();
	if ( 'direct' !== $method ) {
		return new WP_Error( 'mp_fs', 'Accès direct aux fichiers indisponible sur ce serveur (méthode : ' . $method . ').' );
	}
	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'mp_fs', 'Initialisation du système de fichiers impossible.' );
	}
	return $wp_filesystem;
}

function mp_lock() {
	if ( get_transient( 'mp_lock' ) ) {
		return new WP_Error( 'mp_busy', 'Une autre opération de maintenance est en cours. Réessayer dans quelques minutes.' );
	}
	set_transient( 'mp_lock', time(), 15 * MINUTE_IN_SECONDS );
	return true;
}

function mp_unlock() {
	delete_transient( 'mp_lock' );
}

function mp_file_mods_blocked() {
	return defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
}

function mp_protect_snap_dir() {
	wp_mkdir_p( MP_SNAP_DIR );
	if ( ! file_exists( MP_SNAP_DIR . '/.htaccess' ) ) {
		@file_put_contents( MP_SNAP_DIR . '/.htaccess', "Require all denied\nDeny from all\n" );
	}
	if ( ! file_exists( MP_SNAP_DIR . '/index.php' ) ) {
		@file_put_contents( MP_SNAP_DIR . '/index.php', "<?php\n// Silence.\n" );
	}
}

function mp_source_dir( $type, $slug ) {
	if ( 'plugin' === $type ) {
		return WP_PLUGIN_DIR . '/' . $slug;
	}
	return get_theme_root( $slug ) . '/' . $slug;
}

/**
 * Copie de sécurité du dossier d'une extension ou d'un thème avant mise à jour.
 */
function mp_snapshot( $type, $slug, $version ) {
	$fs = mp_fs();
	if ( is_wp_error( $fs ) ) {
		return $fs;
	}
	$src = mp_source_dir( $type, $slug );
	if ( ! is_dir( $src ) ) {
		return new WP_Error( 'mp_snapshot', 'Dossier source introuvable : ' . $src );
	}
	mp_protect_snap_dir();
	$base = MP_SNAP_DIR . '/' . $type . '/' . sanitize_file_name( $slug );
	$dest = $base . '/' . gmdate( 'Ymd-His' ) . '_' . sanitize_file_name( $version );
	if ( ! wp_mkdir_p( $dest ) ) {
		return new WP_Error( 'mp_snapshot', 'Création du dossier de copie impossible.' );
	}
	$copied = copy_dir( $src, $dest );
	if ( is_wp_error( $copied ) ) {
		$fs->delete( $dest, true );
		return $copied;
	}
	mp_prune_snapshots( $base );
	return $dest;
}

function mp_list_snapshots( $base ) {
	if ( ! is_dir( $base ) ) {
		return array();
	}
	$dirs = glob( $base . '/*', GLOB_ONLYDIR );
	if ( ! $dirs ) {
		return array();
	}
	rsort( $dirs ); // Les noms commencent par la date : le plus récent en premier.
	return $dirs;
}

function mp_prune_snapshots( $base ) {
	global $wp_filesystem;
	$dirs = mp_list_snapshots( $base );
	foreach ( array_slice( $dirs, MP_SNAP_KEEP ) as $old ) {
		$wp_filesystem->delete( $old, true );
	}
}

function mp_read_wp_version() {
	$content = @file_get_contents( ABSPATH . WPINC . '/version.php' );
	if ( $content && preg_match( "/\\\$wp_version\s*=\s*'([^']+)'/", $content, $m ) ) {
		return $m[1];
	}
	return get_bloginfo( 'version' );
}

function mp_backup_status() {
	$reports = get_option( 'wpvivid_backup_reports' );
	if ( ! is_array( $reports ) || empty( $reports ) ) {
		return array(
			'outil'   => is_plugin_active( 'wpvivid-backuprestore/wpvivid-backuprestore.php' ) ? 'WPvivid' : null,
			'message' => 'Aucun rapport de sauvegarde trouvé.',
		);
	}
	$last_ok  = null;
	$last_any = null;
	foreach ( $reports as $r ) {
		$t = isset( $r['backup_time'] ) ? (int) $r['backup_time'] : 0;
		if ( ! $last_any || $t > (int) $last_any['backup_time'] ) {
			$last_any = $r;
		}
		if ( isset( $r['status'] ) && 'Succeeded' === $r['status'] && ( ! $last_ok || $t > (int) $last_ok['backup_time'] ) ) {
			$last_ok = $r;
		}
	}
	$schedule = get_option( 'wpvivid_schedule_setting' );
	return array(
		'outil'                      => 'WPvivid',
		'derniere_reussie'           => $last_ok ? gmdate( 'c', (int) $last_ok['backup_time'] ) : null,
		'age_heures_derniere_reussie' => $last_ok ? round( ( time() - (int) $last_ok['backup_time'] ) / 3600, 1 ) : null,
		'derniere_tentative_statut'  => $last_any['status'] ?? null,
		'planification'              => is_array( $schedule ) && ! empty( $schedule['enable'] ) ? ( $schedule['type'] ?? 'active' ) : 'desactivee',
		'copie_distante'             => is_array( $schedule ) && ! empty( $schedule['backup']['remote'] ),
	);
}

function mp_tail( $file, $lines = 15 ) {
	$size = @filesize( $file );
	if ( ! $size ) {
		return array();
	}
	$fh = @fopen( $file, 'rb' );
	if ( ! $fh ) {
		return array();
	}
	$read = min( $size, 12000 );
	fseek( $fh, -$read, SEEK_END );
	$chunk = fread( $fh, $read );
	fclose( $fh );
	$all = preg_split( '/\r\n|\n|\r/', trim( (string) $chunk ) );
	return array_slice( $all, -$lines );
}

function mp_recent_logs() {
	$candidates = array_filter(
		array(
			WP_CONTENT_DIR . '/debug.log',
			ABSPATH . 'error_log',
			WP_CONTENT_DIR . '/error_log',
			ini_get( 'error_log' ),
		)
	);
	$seen   = array();
	$out    = array();
	$limit  = time() - DAY_IN_SECONDS;
	$graves = array( 'Fatal error', 'Erreur fatale', 'Parse error', 'Uncaught', 'Allowed memory size' );
	foreach ( $candidates as $file ) {
		$real = @realpath( $file );
		if ( ! $real || isset( $seen[ $real ] ) || ! @is_readable( $real ) ) {
			continue;
		}
		$seen[ $real ] = true;
		if ( (int) @filemtime( $real ) < $limit ) {
			continue;
		}
		$recent = array();
		$severe = 0;
		foreach ( mp_tail( $real, 200 ) as $line ) {
			// Ne garde que les lignes horodatées des dernières 24 heures.
			if ( ! preg_match( '/^\[([^\]]+)\]/', $line, $m ) ) {
				continue;
			}
			$ts = strtotime( str_replace( ' UTC', ' +0000', $m[1] ) );
			if ( ! $ts || $ts < $limit ) {
				continue;
			}
			$is_severe = false;
			foreach ( $graves as $g ) {
				if ( false !== stripos( $line, $g ) ) {
					$is_severe = true;
					break;
				}
			}
			if ( $is_severe ) {
				$severe++;
			}
			$recent[] = ( $is_severe ? '[GRAVE] ' : '' ) . mb_substr( $line, 0, 400 );
		}
		if ( empty( $recent ) ) {
			continue;
		}
		$out[] = array(
			'fichier'              => str_replace( ABSPATH, '', $real ),
			'erreurs_graves_24h'   => $severe,
			'lignes_24h'           => count( $recent ),
			'dernieres_lignes'     => array_slice( $recent, -10 ),
		);
	}
	return $out;
}

function mp_check_url( $url ) {
	$start    = microtime( true );
	$response = wp_remote_get(
		add_query_arg( 'mp_check', time(), $url ),
		array(
			'timeout'     => 30,
			'redirection' => 5,
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
			'user-agent'  => 'MaintenancePilote/' . MP_VERSION,
		)
	);
	$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
	if ( is_wp_error( $response ) ) {
		return array( 'url' => $url, 'ok' => false, 'erreur' => $response->get_error_message(), 'temps_ms' => $ms );
	}
	$code    = (int) wp_remote_retrieve_response_code( $response );
	$body    = (string) wp_remote_retrieve_body( $response );
	$markers = array(
		'Fatal error',
		'Parse error',
		'Uncaught',
		'critical error',
		'erreur critique',
		'Warning</b>:',
		'Briefly unavailable for scheduled maintenance',
		'Brièvement indisponible pour cause de maintenance',
		'Error establishing a database connection',
		'Erreur lors de la connexion à la base de données',
	);
	$found = array();
	foreach ( $markers as $m ) {
		if ( false !== stripos( $body, $m ) ) {
			$found[] = $m;
		}
	}
	$title = '';
	if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $body, $t ) ) {
		$title = trim( wp_strip_all_tags( html_entity_decode( $t[1], ENT_QUOTES, 'UTF-8' ) ) );
	}
	$complete = false !== stripos( $body, '</html>' );
	return array(
		'url'           => $url,
		'ok'            => 200 === $code && empty( $found ) && $complete && strlen( $body ) > 2000,
		'code_http'     => $code,
		'temps_ms'      => $ms,
		'taille_octets' => strlen( $body ),
		'titre'         => $title,
		'page_complete' => $complete,
		'anomalies'     => $found,
	);
}

/* -------------------------------------------------------------------------
 * Actions (callbacks des abilities)
 * ---------------------------------------------------------------------- */

/**
 * Cherche une adresse e-mail en clair dans la configuration d'un stockage distant,
 * sans jamais lire ni renvoyer les jetons, mots de passe ou secrets.
 */
function mp_find_email( $data, $depth = 0 ) {
	if ( $depth > 3 || ! is_array( $data ) ) {
		return null;
	}
	foreach ( $data as $key => $value ) {
		if ( preg_match( '/token|secret|pass|key|auth|refresh|access|code/i', (string) $key ) ) {
			continue;
		}
		if ( is_string( $value ) && is_email( trim( $value ) ) ) {
			return trim( $value );
		}
		if ( is_array( $value ) ) {
			$found = mp_find_email( $value, $depth + 1 );
			if ( $found ) {
				return $found;
			}
		}
	}
	return null;
}

/**
 * Suivi détaillé des sauvegardes WPvivid : historique, planification, stockage distant,
 * dernière erreur, et autres extensions de sauvegarde actives.
 */
function mp_ability_backups( $input = null ) {
	mp_load_admin();
	global $wpdb;
	$file   = 'wpvivid-backuprestore/wpvivid-backuprestore.php';
	$all    = get_plugins();
	$out    = array(
		'wpvivid' => array(
			'installe' => isset( $all[ $file ] ),
			'actif'    => is_plugin_active( $file ),
			'version'  => isset( $all[ $file ] ) ? $all[ $file ]['Version'] : null,
		),
	);

	// Historique (rapports de tâches WPvivid).
	$reports = get_option( 'wpvivid_backup_reports' );
	$hist    = array();
	if ( is_array( $reports ) ) {
		foreach ( $reports as $r ) {
			if ( ! is_array( $r ) || empty( $r['backup_time'] ) ) {
				continue;
			}
			$st     = isset( $r['status'] ) ? (string) $r['status'] : '';
			$hist[] = array(
				'date'   => gmdate( 'c', (int) $r['backup_time'] ),
				'ts'     => (int) $r['backup_time'],
				'statut' => 'Succeeded' === $st ? 'reussie' : ( in_array( $st, array( 'Failed', 'Error', 'error' ), true ) ? 'echec' : ( '' !== $st ? strtolower( $st ) : 'inconnu' ) ),
			);
		}
	}
	usort( $hist, function ( $a, $b ) { return $b['ts'] - $a['ts']; } );
	$since    = time() - 30 * DAY_IN_SECONDS;
	$ok30     = 0;
	$ko30     = 0;
	$last_ok  = null;
	foreach ( $hist as $h ) {
		if ( $h['ts'] >= $since ) {
			if ( 'reussie' === $h['statut'] ) {
				$ok30++;
			} elseif ( 'echec' === $h['statut'] ) {
				$ko30++;
			}
		}
		if ( ! $last_ok && 'reussie' === $h['statut'] ) {
			$last_ok = $h;
		}
	}
	$out['derniere_reussie']            = $last_ok ? $last_ok['date'] : null;
	$out['age_heures_derniere_reussie'] = $last_ok ? round( ( time() - $last_ok['ts'] ) / 3600, 1 ) : null;
	$out['derniere_tentative']          = $hist ? array( 'date' => $hist[0]['date'], 'statut' => $hist[0]['statut'] ) : null;
	$out['reussites_30j']               = $ok30;
	$out['echecs_30j']                  = $ko30;
	$out['historique']                  = array_map(
		function ( $h ) { return array( 'date' => $h['date'], 'statut' => $h['statut'] ); },
		array_slice( $hist, 0, 20 )
	);

	// Planification.
	$sch  = get_option( 'wpvivid_schedule_setting' );
	$freq = array(
		'wpvivid_hourly'     => 'toutes les heures',
		'wpvivid_2hours'     => 'toutes les 2 heures',
		'wpvivid_4hours'     => 'toutes les 4 heures',
		'wpvivid_8hours'     => 'toutes les 8 heures',
		'wpvivid_12hours'    => 'toutes les 12 heures',
		'wpvivid_daily'      => 'quotidienne',
		'wpvivid_weekly'     => 'hebdomadaire',
		'wpvivid_fortnightly' => 'toutes les deux semaines',
		'wpvivid_monthly'    => 'mensuelle',
	);
	$type = is_array( $sch ) && ! empty( $sch['type'] ) ? (string) $sch['type'] : '';
	$next = wp_next_scheduled( 'wpvivid_main_schedule_event' );
	$out['planification'] = array(
		'active'    => is_array( $sch ) && ! empty( $sch['enable'] ),
		'frequence' => isset( $freq[ $type ] ) ? $freq[ $type ] : ( '' !== $type ? $type : null ),
		'contenu'   => is_array( $sch ) && isset( $sch['backup']['backup_files'] ) ? (string) $sch['backup']['backup_files'] : null,
		'prochaine' => $next ? gmdate( 'c', (int) $next ) : null,
	);

	// Stockage distant.
	$upl     = get_option( 'wpvivid_upload_setting' );
	$remotes = array();
	$details = array();
	$selected = is_array( $upl ) && isset( $upl['remote_selected'] ) ? (array) $upl['remote_selected'] : array();
	if ( is_array( $upl ) ) {
		foreach ( $upl as $k => $v ) {
			if ( 'remote_selected' === $k || ! is_array( $v ) ) {
				continue;
			}
			$type      = isset( $v['type'] ) ? (string) $v['type'] : (string) $k;
			$remotes[] = $type;
			$details[] = array(
				'type'      => $type,
				'nom'       => isset( $v['name'] ) ? sanitize_text_field( (string) $v['name'] ) : null,
				'dossier'   => isset( $v['path'] ) ? sanitize_text_field( (string) $v['path'] ) : null,
				'compte'    => mp_find_email( $v ),
				'selectionne' => in_array( (string) $k, array_map( 'strval', $selected ), true ),
			);
		}
	}
	$out['stockage_distant'] = array(
		'configure'                 => ! empty( $remotes ),
		'types'                     => array_values( array_unique( $remotes ) ),
		'utilise_par_planification' => is_array( $sch ) && ! empty( $sch['backup']['remote'] ),
		'details'                   => $details,
	);

	// Sauvegardes conservées.
	$list = get_option( 'wpvivid_backup_list' );
	$out['sauvegardes_conservees'] = is_array( $list ) ? count( $list ) : null;

	// Dernière erreur (option WPvivid, dans wp_options ou dans la table propre à WPvivid).
	$msg = get_option( 'wpvivid_last_msg' );
	if ( ! $msg ) {
		$table = $wpdb->prefix . 'wpvivid_options';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", 'wpvivid_last_msg' ) ); // phpcs:ignore
			$msg = $raw ? maybe_unserialize( $raw ) : null;
		}
	}
	$err = null;
	if ( is_array( $msg ) && isset( $msg['status'] ) && is_array( $msg['status'] ) ) {
		$st = $msg['status'];
		if ( isset( $st['str'] ) && in_array( $st['str'], array( 'error', 'failed' ), true ) ) {
			$err = isset( $st['error'] ) ? wp_strip_all_tags( (string) $st['error'] ) : 'Erreur sans détail';
		}
	}
	$out['derniere_erreur'] = $err ? mb_substr( $err, 0, 300 ) : null;

	// Autres extensions de sauvegarde actives (WPvivid doit être le seul outil).
	$others = array(
		'updraftplus/updraftplus.php',
		'backwpup/backwpup.php',
		'duplicator/duplicator.php',
		'duplicator-pro/duplicator-pro.php',
		'all-in-one-wp-migration/all-in-one-wp-migration.php',
		'backup-backup/backup-backup.php',
		'blogvault-real-time-backup/blogvault.php',
		'xcloner-backup-and-restore/xcloner.php',
		'wp-database-backup/wp-database-backup.php',
		'backupbuddy/backupbuddy.php',
		'jetpack-backup/jetpack-backup.php',
		'wp-time-capsule/wp-time-capsule.php',
		'backup/backup.php',
	);
	$found = array();
	foreach ( $others as $o ) {
		if ( isset( $all[ $o ] ) && is_plugin_active( $o ) ) {
			$found[] = $all[ $o ]['Name'];
		}
	}
	$out['autres_extensions_sauvegarde'] = $found;
	$out['verifie_le']                   = gmdate( 'c' );
	return $out;
}

function mp_ability_etat( $input = null ) {
	mp_prepare();
	wp_version_check( array(), true );
	wp_update_plugins();
	wp_update_themes();

	$plugins     = get_plugins();
	$plugin_upd  = get_plugin_updates();
	$plugin_list = array();
	foreach ( $plugins as $file => $data ) {
		$u             = isset( $plugin_upd[ $file ]->update ) ? $plugin_upd[ $file ]->update : null;
		$plugin_list[] = array(
			'fichier'           => $file,
			'nom'               => $data['Name'],
			'version'           => $data['Version'],
			'active'            => is_plugin_active( $file ),
			'nouvelle_version'  => $u ? $u->new_version : null,
			'teste_jusqu_a_wp'  => $u && isset( $u->tested ) ? $u->tested : null,
			'php_requis'        => $u && isset( $u->requires_php ) ? $u->requires_php : null,
			'paquet_disponible' => $u ? ! empty( $u->package ) : null,
		);
	}

	$theme_tr   = get_site_transient( 'update_themes' );
	$theme_list = array();
	foreach ( wp_get_themes() as $stylesheet => $theme ) {
		$u            = isset( $theme_tr->response[ $stylesheet ] ) ? $theme_tr->response[ $stylesheet ] : null;
		$theme_list[] = array(
			'theme'            => $stylesheet,
			'nom'              => $theme->get( 'Name' ),
			'version'          => $theme->get( 'Version' ),
			'actif'            => get_stylesheet() === $stylesheet || get_template() === $stylesheet,
			'nouvelle_version' => $u ? $u['new_version'] : null,
		);
	}

	$core = get_preferred_from_update_core();
	$tr   = array();
	foreach ( wp_get_translation_updates() as $t ) {
		$tr[] = array( 'type' => $t->type, 'element' => $t->slug, 'langue' => $t->language );
	}

	return array(
		'site'           => home_url( '/' ),
		'wordpress'      => array(
			'version'          => mp_read_wp_version(),
			'nouvelle_version' => ( $core && 'upgrade' === $core->response ) ? $core->current : null,
		),
		'php'            => PHP_VERSION,
		'modifications_fichiers_bloquees' => mp_file_mods_blocked(),
		'mode_maintenance_bloque' => file_exists( ABSPATH . '.maintenance' ),
		'extensions'     => $plugin_list,
		'themes'         => $theme_list,
		'traductions'    => $tr,
		'sauvegarde'     => mp_backup_status(),
	);
}

function mp_ability_verifier( $input = null ) {
	$home = home_url( '/' );
	$host = wp_parse_url( $home, PHP_URL_HOST );
	$urls = array( $home );
	if ( is_array( $input ) && ! empty( $input['urls'] ) && is_array( $input['urls'] ) ) {
		foreach ( array_slice( $input['urls'], 0, 10 ) as $u ) {
			$u = esc_url_raw( $u );
			if ( $u && wp_parse_url( $u, PHP_URL_HOST ) === $host ) {
				$urls[] = $u;
			}
		}
	}
	$results = array();
	foreach ( array_unique( $urls ) as $u ) {
		$results[] = mp_check_url( $u );
	}
	$all_ok = ! in_array( false, wp_list_pluck( $results, 'ok' ), true );
	$logs   = mp_recent_logs();
	$severe = array_sum( wp_list_pluck( $logs, 'erreurs_graves_24h' ) );
	return array(
		'ok'                      => $all_ok && ! file_exists( ABSPATH . '.maintenance' ),
		'erreurs_graves_24h'      => (int) $severe,
		'mode_maintenance_bloque' => file_exists( ABSPATH . '.maintenance' ),
		'pages'                   => $results,
		'journaux_recents'        => $logs,
		'verifie_le'              => gmdate( 'c' ),
	);
}

/**
 * Réactive une extension de façon robuste : activate_plugin() d'abord, puis, si WordPress
 * lève une exception ou échoue, ajout direct à la liste des extensions actives.
 */
function mp_reactivate_plugin( $file, $network = false ) {
	mp_load_admin();
	try {
		$r = activate_plugin( $file, '', $network, true );
		if ( ! is_wp_error( $r ) && is_plugin_active( $file ) ) {
			return true;
		}
	} catch ( \Throwable $e ) {
		// On passe à la méthode directe ci-dessous.
	}
	if ( $network && is_multisite() ) {
		$net = get_site_option( 'active_sitewide_plugins' );
		$net = is_array( $net ) ? $net : array();
		$net[ $file ] = time();
		update_site_option( 'active_sitewide_plugins', $net );
	} else {
		$active = get_option( 'active_plugins' );
		$active = is_array( $active ) ? array_values( $active ) : array();
		if ( ! in_array( $file, $active, true ) ) {
			$active[] = $file;
			sort( $active );
			update_option( 'active_plugins', $active );
		}
	}
	wp_cache_delete( 'alloptions', 'options' );
	return is_plugin_active( $file );
}

function mp_ability_activate_plugin( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	mp_prepare();
	$files = array();
	if ( ! empty( $input['extensions'] ) && is_array( $input['extensions'] ) ) {
		$files = $input['extensions'];
	} elseif ( ! empty( $input['extension'] ) ) {
		$files = array( $input['extension'] );
	}
	$all = get_plugins();
	$out = array();
	foreach ( array_slice( $files, 0, 40 ) as $file ) {
		$file = (string) $file;
		if ( ! isset( $all[ $file ] ) ) {
			$out[] = array( 'extension' => $file, 'statut' => 'introuvable' );
			continue;
		}
		if ( is_plugin_active( $file ) ) {
			$out[] = array( 'extension' => $file, 'statut' => 'deja_active' );
			continue;
		}
		$out[] = array( 'extension' => $file, 'statut' => mp_reactivate_plugin( $file ) ? 'activee' : 'echec' );
	}
	return array( 'resultats' => $out );
}

function mp_ability_update_plugin( $input = null ) {
	$file = is_array( $input ) && isset( $input['extension'] ) ? (string) $input['extension'] : '';
	mp_prepare();
	if ( mp_file_mods_blocked() ) {
		return new WP_Error( 'mp_blocked', 'Les modifications de fichiers sont désactivées (DISALLOW_FILE_MODS).' );
	}
	$lock = mp_lock();
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$all = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			return new WP_Error( 'mp_unknown', "Extension introuvable : $file. Utiliser la valeur « fichier » renvoyée par maintenance-pilote/etat." );
		}
		wp_update_plugins();
		$updates = get_plugin_updates();
		$before  = $all[ $file ]['Version'];
		if ( ! isset( $updates[ $file ] ) ) {
			return array( 'statut' => 'deja_a_jour', 'extension' => $file, 'version' => $before );
		}
		if ( empty( $updates[ $file ]->update->package ) ) {
			return new WP_Error( 'mp_package', 'Aucun paquet téléchargeable pour cette extension (licence premium inactive ?). Mise à jour non effectuée.' );
		}

		$was_active  = is_plugin_active( $file );
		$was_network = is_multisite() && is_plugin_active_for_network( $file );
		$slug        = dirname( $file );
		$snapshot    = null;
		if ( '.' !== $slug ) {
			$snapshot = mp_snapshot( 'plugin', $slug, $before );
			if ( is_wp_error( $snapshot ) ) {
				return new WP_Error( 'mp_snapshot', 'Copie de sécurité impossible, mise à jour annulée : ' . $snapshot->get_error_message() );
			}
		}

		$skin      = new Automatic_Upgrader_Skin();
		$upgrader  = new Plugin_Upgrader( $skin );
		$exception = null;

		// Verrou : pendant la mise à jour, aucune écriture ne peut retirer cette extension
		// de la liste des extensions actives (même comportement que WP-CLI).
		$guard = null;
		if ( $was_active && ! $was_network ) {
			$guard = function ( $new, $old ) use ( $file ) {
				$new = is_array( $new ) ? $new : ( is_array( $old ) ? $old : array() );
				if ( is_array( $old ) && in_array( $file, $old, true ) && ! in_array( $file, $new, true ) ) {
					$new[] = $file;
					sort( $new );
				}
				return array_values( $new );
			};
			add_filter( 'pre_update_option_active_plugins', $guard, PHP_INT_MAX, 2 );
		}
		try {
			$result = $upgrader->upgrade( $file );
		} catch ( \Throwable $e ) {
			$result    = new WP_Error( 'mp_exception', $e->getMessage() );
			$exception = $e->getMessage();
		}
		if ( $guard ) {
			remove_filter( 'pre_update_option_active_plugins', $guard, PHP_INT_MAX );
		}

		wp_clean_plugins_cache( true );
		$after_all   = get_plugins();
		$after       = isset( $after_all[ $file ] ) ? $after_all[ $file ]['Version'] : null;
		$reactivated = false;
		// Quoi qu'il arrive, une extension active avant la mise à jour doit l'être après.
		if ( $was_active && $after && ! is_plugin_active( $file ) ) {
			$reactivated = mp_reactivate_plugin( $file, $was_network );
		}
		// Réussite = nouvelle version installée et extension dans le bon état d'activation.
		// Une erreur levée APRÈS l'installation (souvent par une autre extension) devient un avertissement.
		$installed = $after && version_compare( $after, $before, '>' );
		$ok        = $installed && ( ! $was_active || is_plugin_active( $file ) );

		return array(
			'statut'                  => $ok ? 'mis_a_jour' : 'echec',
			'extension'               => $file,
			'nom'                     => $all[ $file ]['Name'],
			'version_avant'           => $before,
			'version_apres'           => $after,
			'active'                  => $after ? is_plugin_active( $file ) : false,
			'reactivee_apres_maj'     => $reactivated,
			'retour_arriere_possible' => (bool) $snapshot,
			'etait_active'            => $was_active,
			'erreur'                  => ( ! $ok && is_wp_error( $result ) ) ? $result->get_error_message() : null,
			'avertissement'           => ( $ok && is_wp_error( $result ) ) ? 'Mise à jour installée, mais une autre extension a signalé une erreur pendant la finalisation : ' . $result->get_error_message() : null,
			'messages'                => array_values( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) ),
		);
	} catch ( \Throwable $e ) {
		if ( ! empty( $guard ) ) {
			remove_filter( 'pre_update_option_active_plugins', $guard, PHP_INT_MAX );
		}
		// Filet de sécurité : ne jamais laisser une extension désactivée après une erreur.
		if ( ! empty( $was_active ) && ! is_plugin_active( $file ) ) {
			mp_reactivate_plugin( $file, ! empty( $was_network ) );
		}
		return new WP_Error( 'mp_exception', 'Erreur pendant la mise à jour : ' . $e->getMessage() . ( ! empty( $was_active ) ? ( is_plugin_active( $file ) ? ' (extension réactivée)' : ' (ATTENTION : extension restée désactivée)' ) : '' ) );
	} finally {
		mp_unlock();
	}
}

function mp_ability_update_theme( $input = null ) {
	$stylesheet = is_array( $input ) && isset( $input['theme'] ) ? (string) $input['theme'] : '';
	mp_prepare();
	if ( mp_file_mods_blocked() ) {
		return new WP_Error( 'mp_blocked', 'Les modifications de fichiers sont désactivées (DISALLOW_FILE_MODS).' );
	}
	$lock = mp_lock();
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $stylesheet || ! $theme->exists() ) {
			return new WP_Error( 'mp_unknown', "Thème introuvable : $stylesheet." );
		}
		wp_update_themes();
		$tr     = get_site_transient( 'update_themes' );
		$before = $theme->get( 'Version' );
		if ( empty( $tr->response[ $stylesheet ] ) ) {
			return array( 'statut' => 'deja_a_jour', 'theme' => $stylesheet, 'version' => $before );
		}
		$snapshot = mp_snapshot( 'theme', $stylesheet, $before );
		if ( is_wp_error( $snapshot ) ) {
			return new WP_Error( 'mp_snapshot', 'Copie de sécurité impossible, mise à jour annulée : ' . $snapshot->get_error_message() );
		}
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $stylesheet );
		wp_clean_themes_cache();
		$after = wp_get_theme( $stylesheet )->get( 'Version' );
		$ok    = ( true === $result ) && version_compare( (string) $after, (string) $before, '>' );
		return array(
			'statut'                  => $ok ? 'mis_a_jour' : 'echec',
			'theme'                   => $stylesheet,
			'version_avant'           => $before,
			'version_apres'           => $after,
			'retour_arriere_possible' => true,
			'erreur'                  => is_wp_error( $result ) ? $result->get_error_message() : null,
			'messages'                => array_values( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) ),
		);
	} finally {
		mp_unlock();
	}
}

function mp_ability_update_translations( $input = null ) {
	mp_prepare();
	if ( mp_file_mods_blocked() ) {
		return new WP_Error( 'mp_blocked', 'Les modifications de fichiers sont désactivées (DISALLOW_FILE_MODS).' );
	}
	$lock = mp_lock();
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		wp_update_plugins();
		wp_update_themes();
		$pending = wp_get_translation_updates();
		if ( empty( $pending ) ) {
			return array( 'statut' => 'deja_a_jour', 'traductions' => array() );
		}
		$list = array();
		foreach ( $pending as $t ) {
			$list[] = $t->type . ' : ' . $t->slug . ' (' . $t->language . ')';
		}
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Language_Pack_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade();
		$failed   = 0;
		if ( is_array( $result ) ) {
			foreach ( $result as $r ) {
				if ( ! $r || is_wp_error( $r ) ) {
					$failed++;
				}
			}
		}
		return array(
			'statut'      => ( is_array( $result ) && 0 === $failed ) ? 'mis_a_jour' : 'echec_partiel',
			'traductions' => $list,
			'echecs'      => $failed,
			'messages'    => array_values( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) ),
		);
	} finally {
		mp_unlock();
	}
}

function mp_ability_update_core( $input = null ) {
	mp_prepare();
	if ( mp_file_mods_blocked() ) {
		return new WP_Error( 'mp_blocked', 'Les modifications de fichiers sont désactivées (DISALLOW_FILE_MODS).' );
	}
	$lock = mp_lock();
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		wp_version_check( array(), true );
		$update = get_preferred_from_update_core();
		$before = mp_read_wp_version();
		if ( ! $update || 'upgrade' !== $update->response ) {
			return array( 'statut' => 'deja_a_jour', 'version' => $before );
		}
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $update );
		$after    = mp_read_wp_version();
		return array(
			'statut'        => ( ! is_wp_error( $result ) && version_compare( $after, $before, '>' ) ) ? 'mis_a_jour' : 'echec',
			'version_avant' => $before,
			'version_apres' => $after,
			'erreur'        => is_wp_error( $result ) ? $result->get_error_message() : null,
			'remarque'      => 'Pas de retour arrière automatique pour le cœur : en cas de problème, restaurer la sauvegarde complète.',
			'messages'      => array_values( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) ),
		);
	} finally {
		mp_unlock();
	}
}

function mp_ability_restore( $input = null ) {
	$type    = is_array( $input ) && isset( $input['type'] ) ? (string) $input['type'] : '';
	$element = is_array( $input ) && isset( $input['element'] ) ? (string) $input['element'] : '';
	mp_prepare();
	if ( ! in_array( $type, array( 'extension', 'theme' ), true ) || '' === $element ) {
		return new WP_Error( 'mp_input', 'Indiquer type (extension ou theme) et element (fichier de l\'extension ou identifiant du thème).' );
	}
	$lock = mp_lock();
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$fs = mp_fs();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		$kind = 'extension' === $type ? 'plugin' : 'theme';
		$slug = 'plugin' === $kind ? dirname( $element ) : $element;
		if ( '.' === $slug || '' === $slug ) {
			return new WP_Error( 'mp_input', 'Retour arrière impossible pour une extension sans dossier.' );
		}
		$snaps = mp_list_snapshots( MP_SNAP_DIR . '/' . $kind . '/' . sanitize_file_name( $slug ) );
		if ( empty( $snaps ) ) {
			return new WP_Error( 'mp_nosnap', 'Aucune copie de sécurité disponible pour cet élément.' );
		}
		$snap   = $snaps[0];
		$target = mp_source_dir( $kind, $slug );
		$tmp    = $target . '.mp-old-' . time();

		if ( is_dir( $target ) && ! $fs->move( $target, $tmp ) ) {
			return new WP_Error( 'mp_restore', 'Impossible de déplacer la version actuelle. Rien n\'a été modifié.' );
		}
		wp_mkdir_p( $target );
		$copied = copy_dir( $snap, $target );
		if ( is_wp_error( $copied ) ) {
			$fs->delete( $target, true );
			if ( is_dir( $tmp ) ) {
				$fs->move( $tmp, $target );
			}
			return new WP_Error( 'mp_restore', 'Copie de la sauvegarde impossible, version actuelle remise en place : ' . $copied->get_error_message() );
		}
		if ( is_dir( $tmp ) ) {
			$fs->delete( $tmp, true );
		}

		if ( 'plugin' === $kind ) {
			wp_clean_plugins_cache( true );
			$all     = get_plugins();
			$version = isset( $all[ $element ] ) ? $all[ $element ]['Version'] : null;
			if ( $version && ! is_plugin_active( $element ) ) {
				mp_reactivate_plugin( $element );
			}
		} else {
			wp_clean_themes_cache();
			$version = wp_get_theme( $slug )->get( 'Version' );
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
		return array(
			'statut'            => 'restaure',
			'type'              => $type,
			'element'           => $element,
			'version_restauree' => $version,
			'copie_utilisee'    => basename( $snap ),
		);
	} finally {
		mp_unlock();
	}
}

/* -------------------------------------------------------------------------
 * Mises à jour depuis GitHub (releases du dépôt MP_GITHUB_REPO)
 * Chaque release doit contenir le fichier maintenance-pilote.zip
 * (dossier maintenance-pilote/ à la racine). Tag : v2.3.0, v2.4.0…
 * ---------------------------------------------------------------------- */

/** Dernière version publiée sur GitHub (mise en cache 12 h, 1 h en cas d'échec). */
function mp_github_latest( $force = false ) {
	$cached = get_site_transient( 'mp_github_latest' );
	if ( ! $force && is_array( $cached ) ) {
		return $cached;
	}
	$r = wp_remote_get(
		'https://api.github.com/repos/' . MP_GITHUB_REPO . '/releases/latest',
		array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'MaintenancePilote/' . MP_VERSION . '; ' . home_url( '/' ),
			),
		)
	);
	$info = array( 'ok' => false, 'checked' => time() );
	if ( is_wp_error( $r ) ) {
		$info['error'] = 'GitHub injoignable : ' . $r->get_error_message();
	} else {
		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 === $code && is_array( $data ) && ! empty( $data['tag_name'] ) ) {
			$package = null;
			foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
					$package = $asset['browser_download_url'];
					if ( 'maintenance-pilote.zip' === $asset['name'] ) {
						break;
					}
				}
			}
			$info = array(
				'ok'        => true,
				'checked'   => time(),
				'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
				'package'   => $package,
				'notes'     => (string) ( $data['body'] ?? '' ),
				'url'       => (string) ( $data['html_url'] ?? 'https://github.com/' . MP_GITHUB_REPO ),
				'published' => (string) ( $data['published_at'] ?? '' ),
			);
			if ( ! $package ) {
				$info['ok']    = false;
				$info['error'] = 'La dernière release ne contient pas de fichier maintenance-pilote.zip.';
			}
		} elseif ( 404 === $code ) {
			$info['error'] = 'Aucune release publiée, ou dépôt privé ou introuvable (' . MP_GITHUB_REPO . ').';
		} elseif ( 403 === $code || 429 === $code ) {
			$info['error'] = 'Limite de requêtes GitHub atteinte, nouvel essai dans une heure.';
		} else {
			$info['error'] = 'Réponse inattendue de GitHub (code ' . $code . ').';
		}
	}
	set_site_transient( 'mp_github_latest', $info, $info['ok'] ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $info;
}

function mp_update_object( $info ) {
	return (object) array(
		'id'            => 'github.com/' . MP_GITHUB_REPO,
		'slug'          => 'maintenance-pilote',
		'plugin'        => plugin_basename( __FILE__ ),
		'new_version'   => $info['version'],
		'url'           => $info['url'],
		'package'       => $info['package'],
		'requires_php'  => '7.4',
		'icons'         => array(),
		'banners'       => array(),
		'compatibility' => new stdClass(),
	);
}

add_filter(
	'pre_set_site_transient_update_plugins',
	function ( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$info = mp_github_latest();
		$base = plugin_basename( __FILE__ );
		if ( ! empty( $info['ok'] ) && version_compare( $info['version'], MP_VERSION, '>' ) ) {
			$transient->response[ $base ] = mp_update_object( $info );
			unset( $transient->no_update[ $base ] );
		} else {
			$obj = ! empty( $info['ok'] ) ? mp_update_object( $info ) : (object) array( 'slug' => 'maintenance-pilote', 'plugin' => $base, 'new_version' => MP_VERSION, 'url' => 'https://github.com/' . MP_GITHUB_REPO, 'package' => '' );
			$obj->new_version = MP_VERSION;
			$transient->no_update[ $base ] = $obj;
			unset( $transient->response[ $base ] );
		}
		return $transient;
	}
);

add_filter(
	'plugins_api',
	function ( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'maintenance-pilote' !== $args->slug ) {
			return $result;
		}
		$info = mp_github_latest();
		return (object) array(
			'name'          => 'Maintenance Pilote',
			'slug'          => 'maintenance-pilote',
			'version'       => ! empty( $info['ok'] ) ? $info['version'] : MP_VERSION,
			'author'        => 'BK COM AND MARK',
			'homepage'      => 'https://github.com/' . MP_GITHUB_REPO,
			'download_link' => $info['package'] ?? '',
			'last_updated'  => $info['published'] ?? '',
			'requires_php'  => '7.4',
			'sections'      => array(
				'changelog' => ! empty( $info['notes'] ) ? wpautop( esc_html( $info['notes'] ) ) : 'Voir les releases sur GitHub.',
			),
		);
	},
	10,
	3
);

// Le .zip doit installer le dossier maintenance-pilote/ (renommage de sécurité si besoin).
add_filter(
	'upgrader_source_selection',
	function ( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( __FILE__ ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . 'maintenance-pilote/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'mp_source', 'Archive de mise à jour inattendue : dossier maintenance-pilote introuvable.' );
	},
	10,
	4
);

// Mise à jour automatique activée pour Maintenance Pilote uniquement (désactivable par MP_DISABLE_AUTO_UPDATE).
add_filter(
	'auto_update_plugin',
	function ( $update, $item ) {
		if ( isset( $item->plugin ) && plugin_basename( __FILE__ ) === $item->plugin ) {
			return ! ( defined( 'MP_DISABLE_AUTO_UPDATE' ) && MP_DISABLE_AUTO_UPDATE );
		}
		return $update;
	},
	10,
	2
);

add_action(
	'upgrader_process_complete',
	function () {
		delete_site_transient( 'mp_github_latest' );
	}
);

/* -------------------------------------------------------------------------
 * Clé API Mammouth : stockage chiffré et page de réglages
 * ---------------------------------------------------------------------- */

function mp_crypto_key() {
	$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ) . wp_salt( 'auth' );
	return hash( 'sha256', 'maintenance-pilote|' . $material, true );
}

function mp_encrypt( $plain ) {
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return new WP_Error( 'mp_crypto', 'Le module OpenSSL de PHP est indisponible : impossible de chiffrer la clé.' );
	}
	$iv     = random_bytes( 16 );
	$cipher = openssl_encrypt( $plain, 'aes-256-cbc', mp_crypto_key(), OPENSSL_RAW_DATA, $iv );
	if ( false === $cipher ) {
		return new WP_Error( 'mp_crypto', 'Échec du chiffrement de la clé.' );
	}
	$mac = hash_hmac( 'sha256', $iv . $cipher, mp_crypto_key(), true );
	return base64_encode( $iv . $mac . $cipher );
}

function mp_decrypt( $stored ) {
	if ( ! $stored || ! function_exists( 'openssl_decrypt' ) ) {
		return '';
	}
	$raw = base64_decode( $stored, true );
	if ( false === $raw || strlen( $raw ) < 49 ) {
		return '';
	}
	$iv     = substr( $raw, 0, 16 );
	$mac    = substr( $raw, 16, 32 );
	$cipher = substr( $raw, 48 );
	if ( ! hash_equals( $mac, hash_hmac( 'sha256', $iv . $cipher, mp_crypto_key(), true ) ) ) {
		return '';
	}
	$plain = openssl_decrypt( $cipher, 'aes-256-cbc', mp_crypto_key(), OPENSSL_RAW_DATA, $iv );
	return false === $plain ? '' : $plain;
}

/** Clé Mammouth : la constante de wp-config.php est prioritaire, sinon la clé enregistrée dans les réglages. */
function mp_get_mammouth_key() {
	if ( defined( 'MP_MAMMOUTH_API_KEY' ) && '' !== MP_MAMMOUTH_API_KEY ) {
		return MP_MAMMOUTH_API_KEY;
	}
	return mp_decrypt( get_option( 'mp_mammouth_key', '' ) );
}

function mp_key_source() {
	if ( defined( 'MP_MAMMOUTH_API_KEY' ) && '' !== MP_MAMMOUTH_API_KEY ) {
		return 'wp-config';
	}
	return get_option( 'mp_mammouth_key' ) ? ( '' !== mp_get_mammouth_key() ? 'reglages' : 'illisible' ) : null;
}

function mp_test_mammouth_key( $key ) {
	$r = wp_remote_get(
		'https://api.mammouth.ai/key/info',
		array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
		)
	);
	if ( is_wp_error( $r ) ) {
		return array( 'ok' => false, 'message' => 'Mammouth injoignable : ' . $r->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $r );
	if ( 200 === $code ) {
		return array( 'ok' => true, 'message' => 'Clé valide : Mammouth l\'a acceptée.' );
	}
	if ( 401 === $code || 403 === $code ) {
		return array( 'ok' => false, 'message' => 'Clé refusée par Mammouth (code ' . $code . '). Vérifie qu\'elle est complète et active.' );
	}
	return array( 'ok' => false, 'message' => 'Réponse inattendue de Mammouth (code ' . $code . ').' );
}

add_action(
	'admin_menu',
	function () {
		add_options_page( 'Maintenance Pilote', 'Maintenance Pilote', 'manage_options', 'maintenance-pilote', 'mp_render_settings' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=maintenance-pilote' ) ) . '">Réglages</a>' );
		return $links;
	}
);

add_action(
	'admin_post_mp_settings',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Accès refusé.' );
		}
		check_admin_referer( 'mp_settings' );
		$do     = isset( $_POST['mp_do'] ) ? sanitize_key( wp_unslash( $_POST['mp_do'] ) ) : '';
		$notice = array( 'ok' => false, 'message' => 'Aucune action.' );

		if ( 'save' === $do ) {
			$key = isset( $_POST['mp_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['mp_key'] ) ) ) : '';
			if ( '' === $key ) {
				$notice = array( 'ok' => false, 'message' => 'Colle une clé avant d\'enregistrer.' );
			} else {
				$enc = mp_encrypt( $key );
				if ( is_wp_error( $enc ) ) {
					$notice = array( 'ok' => false, 'message' => $enc->get_error_message() );
				} else {
					update_option( 'mp_mammouth_key', $enc, false );
					$test   = mp_test_mammouth_key( $key );
					$notice = array( 'ok' => $test['ok'], 'message' => 'Clé enregistrée (chiffrée). ' . $test['message'] );
				}
			}
		} elseif ( 'test' === $do ) {
			$key    = mp_get_mammouth_key();
			$notice = '' === $key ? array( 'ok' => false, 'message' => 'Aucune clé à tester.' ) : mp_test_mammouth_key( $key );
		} elseif ( 'delete' === $do ) {
			delete_option( 'mp_mammouth_key' );
			$notice = array( 'ok' => true, 'message' => 'Clé supprimée des réglages.' );
		} elseif ( 'mcp_generate' === $do ) {
			$secret = wp_generate_password( 48, false, false );
			update_option(
				'mp_mcp',
				array(
					'hash'    => hash( 'sha256', $secret ),
					'user_id' => get_current_user_id(),
					'created' => gmdate( 'c' ),
				),
				false
			);
			set_transient( 'mp_mcp_url_' . get_current_user_id(), mp_mcp_url( $secret ), 15 * MINUTE_IN_SECONDS );
			$notice = array( 'ok' => true, 'message' => 'Nouvelle adresse de connecteur générée. Copie-la maintenant : elle ne sera plus affichée. Toute ancienne adresse est désactivée.' );
		} elseif ( 'mp_check_update' === $do ) {
			$info = mp_github_latest( true );
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
			if ( empty( $info['ok'] ) ) {
				$notice = array( 'ok' => false, 'message' => $info['error'] ?? 'Vérification impossible.' );
			} elseif ( version_compare( $info['version'], MP_VERSION, '>' ) ) {
				$notice = array( 'ok' => true, 'message' => 'Version ' . $info['version'] . ' disponible : elle sera installée automatiquement, ou tout de suite depuis Tableau de bord > Mises à jour.' );
			} else {
				$notice = array( 'ok' => true, 'message' => 'Maintenance Pilote est à jour (version ' . MP_VERSION . ').' );
			}
		} elseif ( 'mcp_revoke' === $do ) {
			delete_option( 'mp_mcp' );
			$notice = array( 'ok' => true, 'message' => 'Connecteur désactivé : plus aucune adresse ne fonctionne.' );
		}
		set_transient( 'mp_notice_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=maintenance-pilote' ) );
		exit;
	}
);

function mp_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$notice = get_transient( 'mp_notice_' . get_current_user_id() );
	delete_transient( 'mp_notice_' . get_current_user_id() );
	$source = mp_key_source();
	$key    = mp_get_mammouth_key();
	$masked = $key ? '•••• ' . esc_html( substr( $key, -4 ) ) : '';
	?>
	<div class="wrap">
		<h1>Maintenance Pilote</h1>
		<?php if ( $notice ) : ?>
			<div class="notice <?php echo $notice['ok'] ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
		<?php endif; ?>

		<h2>Mises à jour de l'extension</h2>
		<?php $gh = get_site_transient( 'mp_github_latest' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Version installée</th>
				<td><strong><?php echo esc_html( MP_VERSION ); ?></strong>
				<?php if ( is_array( $gh ) && ! empty( $gh['ok'] ) ) : ?>
					— dernière version publiée : <?php echo esc_html( $gh['version'] ); ?><?php echo version_compare( $gh['version'], MP_VERSION, '>' ) ? ' (mise à jour en attente)' : ' (à jour)'; ?>
				<?php elseif ( is_array( $gh ) && ! empty( $gh['error'] ) ) : ?>
					— <span style="color:#b32d2e"><?php echo esc_html( $gh['error'] ); ?></span>
				<?php endif; ?>
				<p class="description">Source : github.com/<?php echo esc_html( MP_GITHUB_REPO ); ?>. Mise à jour automatique <?php echo ( defined( 'MP_DISABLE_AUTO_UPDATE' ) && MP_DISABLE_AUTO_UPDATE ) ? '<strong>désactivée</strong> (MP_DISABLE_AUTO_UPDATE)' : 'activée'; ?>.<?php if ( is_array( $gh ) && ! empty( $gh['checked'] ) ) : ?> Dernière vérification : <?php echo esc_html( wp_date( 'j F Y à H:i', (int) $gh['checked'] ) ); ?>.<?php endif; ?></p>
				</td>
			</tr>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mp_settings">
			<?php wp_nonce_field( 'mp_settings' ); ?>
			<p class="submit" style="margin-top:0;padding-top:0"><button type="submit" name="mp_do" value="mp_check_update" class="button">Vérifier maintenant</button></p>
		</form>

		<h2>Connexion à Claude</h2>
		<p>Adresse secrète à ajouter dans Claude comme connecteur personnalisé. Elle donne accès uniquement aux actions de Maintenance Pilote, exécutées avec les droits du compte qui l'a générée. Ne la partage pas.</p>
		<?php
		$mcp     = get_option( 'mp_mcp' );
		$new_url = get_transient( 'mp_mcp_url_' . get_current_user_id() );
		delete_transient( 'mp_mcp_url_' . get_current_user_id() );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">État</th>
				<td>
					<?php if ( is_array( $mcp ) && ! empty( $mcp['hash'] ) ) : ?>
						<?php $owner = get_userdata( (int) $mcp['user_id'] ); ?>
						<strong>Connecteur actif</strong>, adresse générée le <?php echo esc_html( mysql2date( 'j F Y à H:i', get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $mcp['created'] ) ) ) ) ); ?> par <?php echo esc_html( $owner ? $owner->user_login : 'un compte supprimé' ); ?>.
					<?php else : ?>
						Aucun connecteur : génère une adresse pour connecter ce site à Claude.
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $new_url ) : ?>
			<tr>
				<th scope="row">Adresse du connecteur</th>
				<td>
					<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $new_url ); ?>" onclick="this.select();">
					<p class="description"><strong>Copie-la maintenant, elle ne sera plus affichée.</strong> Dans Claude : Paramètres, Connecteurs, Ajouter un connecteur personnalisé, puis colle cette adresse (sans identifiant OAuth).</p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mp_settings">
			<?php wp_nonce_field( 'mp_settings' ); ?>
			<p class="submit">
				<?php if ( is_array( $mcp ) && ! empty( $mcp['hash'] ) ) : ?>
					<button type="submit" name="mp_do" value="mcp_generate" class="button" onclick="return confirm('Générer une nouvelle adresse ? L\'adresse actuelle cessera de fonctionner et il faudra mettre à jour le connecteur dans Claude.');">Régénérer l'adresse</button>
					<button type="submit" name="mp_do" value="mcp_revoke" class="button button-link-delete" onclick="return confirm('Désactiver le connecteur ? Claude ne pourra plus accéder à ce site.');">Désactiver le connecteur</button>
				<?php else : ?>
					<button type="submit" name="mp_do" value="mcp_generate" class="button button-primary">Générer l'adresse du connecteur</button>
				<?php endif; ?>
			</p>
		</form>

		<h2>Génération d'images (Mammouth)</h2>
		<p>Clé utilisée pour générer les images des articles avec GPT Image. Elle est enregistrée chiffrée et n'est jamais affichée en entier ni transmise à Claude.</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">État</th>
				<td>
					<?php if ( 'wp-config' === $source ) : ?>
						<strong>Clé définie dans wp-config.php</strong> (<?php echo $masked; ?>). Elle est prioritaire sur ces réglages.
					<?php elseif ( 'reglages' === $source ) : ?>
						<strong>Clé enregistrée</strong> (<?php echo $masked; ?>).
					<?php elseif ( 'illisible' === $source ) : ?>
						<strong>Clé enregistrée mais illisible</strong> : les clés secrètes du site ont changé. Colle la clé à nouveau.
					<?php else : ?>
						Aucune clé : la génération d'images est désactivée, les articles utiliseront des images de banque.
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mp_settings">
			<?php wp_nonce_field( 'mp_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mp_key"><?php echo $source ? 'Remplacer la clé' : 'Clé API Mammouth'; ?></label></th>
					<td>
						<input type="password" id="mp_key" name="mp_key" class="regular-text" autocomplete="off" spellcheck="false">
						<p class="description">À créer dans Mammouth : Compte, puis Réglages, puis API. Une clé dédiée à ce site est conseillée.</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" name="mp_do" value="save" class="button button-primary">Enregistrer et tester</button>
				<?php if ( $source ) : ?>
					<button type="submit" name="mp_do" value="test" class="button">Tester la clé</button>
				<?php endif; ?>
				<?php if ( 'reglages' === $source || 'illisible' === $source ) : ?>
					<button type="submit" name="mp_do" value="delete" class="button button-link-delete" onclick="return confirm('Supprimer la clé Mammouth de ce site ?');">Supprimer la clé</button>
				<?php endif; ?>
			</p>
		</form>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Articles et images
 * ---------------------------------------------------------------------- */

/**
 * Enregistre un fichier image temporaire dans la médiathèque, après
 * redimensionnement (1600 px max) et conversion en WebP ou JPEG.
 */
function mp_save_image_file( $tmp, $basename, $meta, $parent = 0 ) {
	mp_load_admin();
	$info = @getimagesize( $tmp );
	if ( ! $info ) {
		@unlink( $tmp );
		return new WP_Error( 'mp_image', 'Le fichier reçu n\'est pas une image valide.' );
	}
	$ext_map = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' );
	$mime    = $info['mime'];
	$ext     = isset( $ext_map[ $mime ] ) ? $ext_map[ $mime ] : 'jpg';

	$editor = wp_get_image_editor( $tmp );
	if ( ! is_wp_error( $editor ) ) {
		$size = $editor->get_size();
		if ( ! empty( $size['width'] ) && $size['width'] > 1600 ) {
			$editor->resize( 1600, null, false );
		}
		$editor->set_quality( 82 );
		$target = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'image/webp' : 'image/jpeg';
		$t_ext  = 'image/webp' === $target ? 'webp' : 'jpg';
		$saved  = $editor->save( $tmp . '-opt.' . $t_ext, $target );
		if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) {
			@unlink( $tmp );
			$tmp = $saved['path'];
			$ext = $t_ext;
		}
	}

	$name = sanitize_title( $basename );
	if ( '' === $name ) {
		$name = 'image-article';
	}
	$file_array = array(
		'name'     => $name . '.' . $ext,
		'tmp_name' => $tmp,
	);
	$post_data = array(
		'post_title'   => isset( $meta['titre'] ) ? sanitize_text_field( $meta['titre'] ) : $name,
		'post_excerpt' => isset( $meta['legende'] ) ? sanitize_text_field( $meta['legende'] ) : '',
		'post_content' => isset( $meta['description'] ) ? sanitize_textarea_field( $meta['description'] ) : '',
	);
	$id = media_handle_sideload( $file_array, (int) $parent, null, $post_data );
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp );
		return $id;
	}
	if ( ! empty( $meta['alt'] ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $meta['alt'] ) );
	}
	return array(
		'image_id' => (int) $id,
		'url'      => wp_get_attachment_url( $id ),
		'format'   => $ext,
	);
}

function mp_image_meta_from_input( $input ) {
	return array(
		'titre'       => $input['titre'] ?? '',
		'alt'         => $input['alt'] ?? '',
		'legende'     => $input['legende'] ?? '',
		'description' => $input['description'] ?? '',
	);
}

function mp_ability_generate_image( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	mp_prepare();
	$api_key = mp_get_mammouth_key();
	if ( '' === $api_key ) {
		return new WP_Error( 'mp_nokey', 'Clé Mammouth absente ou illisible (Réglages > Maintenance Pilote) : utiliser une image de banque.' );
	}
	$prompt = trim( (string) ( $input['prompt'] ?? '' ) );
	if ( '' === $prompt ) {
		return new WP_Error( 'mp_input', 'Le champ prompt est obligatoire.' );
	}
	$model    = defined( 'MP_MAMMOUTH_IMAGE_MODEL' ) ? MP_MAMMOUTH_IMAGE_MODEL : 'gpt-image-2';
	$response = wp_remote_post(
		'https://api.mammouth.ai/v1/chat/completions',
		array(
			'timeout' => 240,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'model'    => $model,
					'messages' => array(
						array( 'role' => 'user', 'content' => $prompt ),
					),
				)
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'mp_api', 'Appel à Mammouth impossible : ' . $response->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );
	if ( 200 !== $code ) {
		return new WP_Error( 'mp_api', 'Mammouth a répondu ' . $code . ' : ' . substr( wp_strip_all_tags( $body ), 0, 300 ) );
	}
	$data = json_decode( $body, true );
	$msg  = $data['choices'][0]['message'] ?? array();
	$src  = $msg['images'][0]['image_url']['url'] ?? null;
	if ( ! $src && ! empty( $msg['content'] ) && is_string( $msg['content'] ) ) {
		if ( preg_match( '#data:image/[a-z]+;base64,[A-Za-z0-9+/=]+#', $msg['content'], $m ) ) {
			$src = $m[0];
		} elseif ( preg_match( '#https?://[^\s\)\]"\']+#', $msg['content'], $m ) ) {
			$src = $m[0];
		}
	}
	if ( ! $src ) {
		return new WP_Error( 'mp_api', 'Aucune image dans la réponse de Mammouth (modèle ' . $model . ').' );
	}
	unset( $data, $body );

	if ( 0 === strpos( $src, 'data:' ) ) {
		$comma = strpos( $src, ',' );
		$bytes = base64_decode( substr( $src, $comma + 1 ) );
		if ( ! $bytes ) {
			return new WP_Error( 'mp_api', 'Image reçue illisible.' );
		}
		$tmp = wp_tempnam( 'mp-image' );
		file_put_contents( $tmp, $bytes );
		unset( $bytes, $src );
	} else {
		$tmp = download_url( $src, 120 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
	}
	$name   = $input['nom_fichier'] ?? ( $input['titre'] ?? 'image-article' );
	$result = mp_save_image_file( $tmp, $name, mp_image_meta_from_input( $input ), (int) ( $input['article_id'] ?? 0 ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$result['source'] = 'Mammouth (' . $model . ')';
	return $result;
}

function mp_ability_import_image( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	mp_prepare();
	$url  = esc_url_raw( (string) ( $input['url'] ?? '' ) );
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$ok   = array( 'images.unsplash.com', 'plus.unsplash.com', 'images.pexels.com', 'cdn.pixabay.com', 'pixabay.com' );
	if ( ! $url || ! in_array( $host, $ok, true ) ) {
		return new WP_Error( 'mp_input', 'Adresse refusée : seules les images Unsplash, Pexels et Pixabay sont acceptées.' );
	}
	$tmp = download_url( $url, 120 );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}
	$name   = $input['nom_fichier'] ?? ( $input['titre'] ?? 'image-article' );
	$result = mp_save_image_file( $tmp, $name, mp_image_meta_from_input( $input ), (int) ( $input['article_id'] ?? 0 ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$result['source'] = $host;
	return $result;
}

function mp_ability_upload_image( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	mp_prepare();
	$data = (string) ( $input['donnees_base64'] ?? '' );
	if ( '' === $data ) {
		return new WP_Error( 'mp_input', 'Aucune donnée d\'image reçue.' );
	}
	if ( 0 === strpos( $data, 'data:' ) ) {
		$data = substr( $data, strpos( $data, ',' ) + 1 );
	}
	if ( strlen( $data ) > 24 * 1024 * 1024 ) {
		return new WP_Error( 'mp_input', 'Image trop lourde (16 Mo maximum).' );
	}
	$bytes = base64_decode( $data, true );
	if ( false === $bytes || '' === $bytes ) {
		return new WP_Error( 'mp_input', 'Données d\'image illisibles.' );
	}
	$tmp = wp_tempnam( 'mp-upload' );
	file_put_contents( $tmp, $bytes );
	unset( $bytes, $data );
	$name   = $input['nom_fichier'] ?? ( $input['titre'] ?? 'image-article' );
	$name   = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', (string) $name );
	$result = mp_save_image_file( $tmp, $name, mp_image_meta_from_input( $input ), 0 );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$result['source'] = 'téléversement';
	return $result;
}

/** Met à jour les textes d'une image de la médiathèque (seulement les champs fournis). */
function mp_describe_image( $id, $meta ) {
	if ( 'attachment' !== get_post_type( $id ) ) {
		return false;
	}
	$post = array( 'ID' => $id );
	if ( ! empty( $meta['titre'] ) ) {
		$post['post_title'] = sanitize_text_field( $meta['titre'] );
	}
	if ( isset( $meta['legende'] ) && '' !== $meta['legende'] ) {
		$post['post_excerpt'] = sanitize_text_field( $meta['legende'] );
	}
	if ( isset( $meta['description'] ) && '' !== $meta['description'] ) {
		$post['post_content'] = sanitize_textarea_field( $meta['description'] );
	}
	if ( count( $post ) > 1 ) {
		wp_update_post( $post );
	}
	if ( ! empty( $meta['alt'] ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $meta['alt'] ) );
	}
	return true;
}

function mp_figure_html( $id, $caption = '' ) {
	$img = wp_get_attachment_image( $id, 'large', false, array( 'class' => 'wp-image-' . $id, 'loading' => 'lazy' ) );
	if ( ! $img ) {
		return '';
	}
	$cap = '' !== $caption ? '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>' : '';
	return '<figure class="wp-block-image size-large">' . $img . $cap . '</figure>';
}

/**
 * Place les images dans le contenu : les repères [[image:N]] sont remplacés par l'image N
 * de la liste ; les images non placées sont réparties avant les intertitres h2 (à partir du deuxième).
 */
function mp_place_images( $content, $images, &$notes ) {
	$placed = array();
	foreach ( $images as $i => $im ) {
		$n    = $i + 1;
		$html = mp_figure_html( (int) $im['image_id'], (string) ( $im['legende'] ?? '' ) );
		if ( '' === $html ) {
			$notes[] = 'Image ' . (int) $im['image_id'] . ' introuvable : non insérée.';
			$placed[ $i ] = true;
			$content = str_replace( '[[image:' . $n . ']]', '', $content );
			continue;
		}
		$pattern = '#(<p>\s*)?\[\[image:' . $n . '\]\](\s*</p>)?#';
		if ( preg_match( $pattern, $content ) ) {
			$content      = preg_replace( $pattern, $html, $content, 1 );
			$content      = preg_replace( $pattern, '', $content );
			$placed[ $i ] = true;
		}
	}
	$content = preg_replace( '#(<p>\s*)?\[\[image:\d+\]\](\s*</p>)?#', '', $content );
	$rest    = array();
	foreach ( $images as $i => $im ) {
		if ( empty( $placed[ $i ] ) ) {
			$rest[] = $im;
		}
	}
	if ( $rest ) {
		$parts = preg_split( '#(?=<h2)#i', $content );
		// $parts[0] = intro, puis une section par h2.
		$slot = 2;
		foreach ( $rest as $im ) {
			$html = mp_figure_html( (int) $im['image_id'], (string) ( $im['legende'] ?? '' ) );
			if ( isset( $parts[ $slot ] ) ) {
				$parts[ $slot ] = $html . $parts[ $slot ];
				$slot++;
			} else {
				$parts[ count( $parts ) - 1 ] .= $html;
			}
		}
		$content = implode( '', $parts );
		$notes[] = count( $rest ) . ' image(s) placée(s) automatiquement avant les intertitres.';
	}
	return $content;
}

function mp_ability_articles_context( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	$n     = min( 100, max( 1, (int) ( $input['nombre'] ?? 40 ) ) );
	$posts = get_posts(
		array(
			'post_type'   => 'post',
			'post_status' => array( 'publish', 'future', 'draft' ),
			'numberposts' => $n,
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);
	$list = array();
	foreach ( $posts as $p ) {
		$list[] = array(
			'id'         => $p->ID,
			'titre'      => mp_plain( get_the_title( $p ) ),
			'slug'       => $p->post_name,
			'statut'     => $p->post_status,
			'date'       => get_the_date( 'Y-m-d', $p ),
			'categories' => array_map( 'mp_plain', wp_get_post_terms( $p->ID, 'category', array( 'fields' => 'names' ) ) ),
			'etiquettes' => array_map( 'mp_plain', wp_get_post_terms( $p->ID, 'post_tag', array( 'fields' => 'names' ) ) ),
			'url'        => get_permalink( $p ),
		);
	}
	$cats = array();
	foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) {
		$cats[] = array( 'id' => $c->term_id, 'nom' => mp_plain( $c->name ), 'articles' => $c->count );
	}
	$tags = array();
	foreach ( get_tags( array( 'orderby' => 'count', 'order' => 'DESC', 'number' => 200, 'hide_empty' => false ) ) as $t ) {
		$tags[] = array( 'id' => $t->term_id, 'nom' => mp_plain( $t->name ), 'articles' => $t->count );
	}
	$pages = array();
	foreach ( get_pages( array( 'post_status' => 'publish', 'number' => 50, 'sort_column' => 'menu_order,post_title' ) ) as $pg ) {
		$pages[] = array( 'titre' => mp_plain( get_the_title( $pg ) ), 'url' => get_permalink( $pg ) );
	}
	return array(
		'site'              => home_url( '/' ),
		'pages'             => $pages,
		'articles'          => $list,
		'categories'        => $cats,
		'etiquettes'        => $tags,
		'extension_seo'     => mp_seo_plugin(),
		'generation_images' => '' !== mp_get_mammouth_key(),
	);
}

function mp_seo_plugin() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'yoast';
	}
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		return 'rankmath';
	}
	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return 'seopress';
	}
	if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
		return 'seoframework';
	}
	if ( defined( 'AIOSEO_VERSION' ) ) {
		return 'aioseo';
	}
	return null;
}

function mp_set_seo( $post_id, $desc, $title = '' ) {
	$plugin = mp_seo_plugin();
	$desc   = sanitize_text_field( $desc );
	$title  = sanitize_text_field( $title );
	$keys   = array(
		'yoast'        => array( '_yoast_wpseo_metadesc', '_yoast_wpseo_title' ),
		'rankmath'     => array( 'rank_math_description', 'rank_math_title' ),
		'seopress'     => array( '_seopress_titles_desc', '_seopress_titles_title' ),
		'seoframework' => array( '_genesis_description', '_genesis_title' ),
	);
	if ( $plugin && isset( $keys[ $plugin ] ) ) {
		if ( $desc ) {
			update_post_meta( $post_id, $keys[ $plugin ][0], $desc );
		}
		if ( $title ) {
			update_post_meta( $post_id, $keys[ $plugin ][1], $title );
		}
		return $plugin;
	}
	if ( 'aioseo' === $plugin && $desc && class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
		$m = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
		$m->post_id     = $post_id;
		$m->description = $desc;
		if ( $title ) {
			$m->title = $title;
		}
		$m->save();
		return 'aioseo';
	}
	return null;
}

/** Trouve un terme existant par identifiant, nom (avec ou sans entités HTML) ou slug. */
function mp_find_term( $value, $taxonomy ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return null;
	}
	if ( ctype_digit( $value ) ) {
		$t = get_term( (int) $value, $taxonomy );
		return ( $t && ! is_wp_error( $t ) ) ? $t : null;
	}
	$plain = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
	foreach ( array( $value, $plain, esc_html( $plain ) ) as $name ) {
		$t = get_term_by( 'name', $name, $taxonomy );
		if ( $t ) {
			return $t;
		}
	}
	$t = get_term_by( 'slug', sanitize_title( $plain ), $taxonomy );
	if ( $t ) {
		return $t;
	}
	// Dernier recours : comparaison insensible à la casse et aux entités.
	foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 500 ) ) as $t ) {
		if ( 0 === strcasecmp( html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), $plain ) ) {
			return $t;
		}
	}
	return null;
}

function mp_plain( $text ) {
	return html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function mp_ability_publish_article( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	mp_prepare();
	$title   = trim( wp_strip_all_tags( (string) ( $input['titre'] ?? '' ) ) );
	$content = (string) ( $input['contenu'] ?? '' );
	if ( '' === $title || '' === trim( $content ) ) {
		return new WP_Error( 'mp_input', 'Titre et contenu obligatoires.' );
	}
	$status = ( $input['statut'] ?? 'publish' ) === 'draft' ? 'draft' : 'publish';

	$notes = array();

	// Catégories : uniquement des catégories existantes (identifiants ou noms), 3 au maximum.
	$wanted = array();
	if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
		$wanted = $input['categories'];
	}
	if ( isset( $input['categorie'] ) && '' !== (string) $input['categorie'] ) {
		$wanted[] = $input['categorie'];
	}
	$cat_ids = array();
	foreach ( array_slice( $wanted, 0, 3 ) as $cat ) {
		$term = mp_find_term( $cat, 'category' );
		if ( $term ) {
			$cat_ids[] = (int) $term->term_id;
		} else {
			$notes[] = 'Catégorie « ' . $cat . ' » introuvable : ignorée (aucune catégorie n\'est créée).';
		}
	}
	$cat_ids = array_values( array_unique( $cat_ids ) );
	if ( empty( $cat_ids ) ) {
		$cat_ids = array( (int) get_option( 'default_category' ) );
		$notes[] = 'Aucune catégorie valide : catégorie par défaut utilisée.';
	}

	// Étiquettes : existantes uniquement, sauf si creer_etiquettes = true.
	$create  = ! empty( $input['creer_etiquettes'] );
	$tag_ids = array();
	if ( ! empty( $input['etiquettes'] ) && is_array( $input['etiquettes'] ) ) {
		foreach ( array_slice( $input['etiquettes'], 0, 8 ) as $t ) {
			$t = sanitize_text_field( (string) $t );
			if ( '' === $t ) {
				continue;
			}
			$term = mp_find_term( $t, 'post_tag' );
			if ( $term ) {
				$tag_ids[] = (int) $term->term_id;
			} elseif ( $create ) {
				$made = wp_insert_term( $t, 'post_tag' );
				if ( ! is_wp_error( $made ) ) {
					$tag_ids[] = (int) $made['term_id'];
				}
			} else {
				$notes[] = 'Étiquette « ' . $t . ' » inexistante : ignorée.';
			}
		}
	}
	$tag_ids = array_values( array_unique( $tag_ids ) );

	// Images dans le texte (repères [[image:N]]).
	$inline = array();
	if ( ! empty( $input['images_texte'] ) && is_array( $input['images_texte'] ) ) {
		foreach ( array_slice( $input['images_texte'], 0, 12 ) as $im ) {
			if ( is_array( $im ) && ! empty( $im['image_id'] ) ) {
				$inline[] = $im;
				mp_describe_image( (int) $im['image_id'], $im );
			}
		}
	}
	if ( $inline || false !== strpos( $content, '[[image:' ) ) {
		$content = mp_place_images( $content, $inline, $notes );
	}

	$postarr = array(
		'post_type'     => 'post',
		'post_status'   => $status,
		'post_title'    => $title,
		'post_content'  => wp_kses_post( $content ),
		'post_excerpt'  => sanitize_textarea_field( (string) ( $input['extrait'] ?? '' ) ),
		'post_category' => $cat_ids,
		'post_author'   => get_current_user_id(),
	);
	if ( ! empty( $input['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( $input['slug'] );
	}
	$id = wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	if ( ! empty( $tag_ids ) ) {
		wp_set_post_terms( $id, $tag_ids, 'post_tag', false );
	}

	if ( ! empty( $input['image_id'] ) ) {
		$img = (int) $input['image_id'];
		if ( 'attachment' === get_post_type( $img ) ) {
			set_post_thumbnail( $id, $img );
			if ( ! empty( $input['image_meta'] ) && is_array( $input['image_meta'] ) ) {
				mp_describe_image( $img, $input['image_meta'] );
			}
			wp_update_post( array( 'ID' => $img, 'post_parent' => $id ) );
		} else {
			$notes[] = 'Image ' . $img . ' introuvable : article publié sans image mise en avant.';
		}
	}
	foreach ( $inline as $im ) {
		if ( 'attachment' === get_post_type( (int) $im['image_id'] ) && ! wp_get_post_parent_id( (int) $im['image_id'] ) ) {
			wp_update_post( array( 'ID' => (int) $im['image_id'], 'post_parent' => $id ) );
		}
	}
	$seo = mp_set_seo( $id, (string) ( $input['meta_description'] ?? '' ), (string) ( $input['titre_seo'] ?? '' ) );

	return array(
		'statut'       => get_post_status( $id ),
		'article_id'   => (int) $id,
		'url'          => get_permalink( $id ),
		'slug'         => get_post_field( 'post_name', $id ),
		'categories'   => array_map( 'get_cat_name', $cat_ids ),
		'etiquettes'   => wp_get_post_terms( $id, 'post_tag', array( 'fields' => 'names' ) ),
		'image_id'     => (int) get_post_thumbnail_id( $id ),
		'seo'          => $seo ? 'Méta-description enregistrée (' . $seo . ')' : 'Aucune extension SEO prise en charge : méta-description non enregistrée.',
		'remarques'    => $notes,
	);
}

function mp_ability_publish_draft( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	$id    = (int) ( $input['article_id'] ?? 0 );
	$post  = get_post( $id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return new WP_Error( 'mp_input', 'Article introuvable.' );
	}
	if ( ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
		return new WP_Error( 'mp_input', 'Seul un brouillon ou un article en attente peut être publié (statut actuel : ' . $post->post_status . ').' );
	}
	$r = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	return array( 'statut' => get_post_status( $id ), 'article_id' => $id, 'url' => get_permalink( $id ) );
}

function mp_ability_update_seo( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	$id    = (int) ( $input['article_id'] ?? 0 );
	$post  = get_post( $id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return new WP_Error( 'mp_input', 'Article introuvable (seuls les articles sont modifiables).' );
	}
	$desc  = (string) ( $input['meta_description'] ?? '' );
	$title = (string) ( $input['titre_seo'] ?? '' );
	if ( '' === $desc && '' === $title ) {
		return new WP_Error( 'mp_input', 'Indiquer titre_seo et/ou meta_description.' );
	}
	$plugin = mp_set_seo( $id, $desc, $title );
	if ( ! $plugin ) {
		return new WP_Error( 'mp_seo', 'Aucune extension SEO prise en charge sur ce site.' );
	}
	return array( 'statut' => 'modifie', 'article_id' => $id, 'extension_seo' => $plugin );
}

/* -------------------------------------------------------------------------
 * Serveur MCP intégré (connexion directe à Claude, sans autre extension)
 * ---------------------------------------------------------------------- */

function mp_ability_names() {
	return array(
		'maintenance-pilote/etat',
		'maintenance-pilote/sauvegardes',
		'maintenance-pilote/verifier-site',
		'maintenance-pilote/mettre-a-jour-extension',
		'maintenance-pilote/mettre-a-jour-theme',
		'maintenance-pilote/mettre-a-jour-traductions',
		'maintenance-pilote/mettre-a-jour-wordpress',
		'maintenance-pilote/restaurer',
		'maintenance-pilote/activer-extension',
		'maintenance-pilote/contexte-articles',
		'maintenance-pilote/generer-image',
		'maintenance-pilote/importer-image',
		'maintenance-pilote/televerser-image',
		'maintenance-pilote/publier-article',
		'maintenance-pilote/publier-brouillon',
		'maintenance-pilote/modifier-seo-article',
	);
}

function mp_mcp_url( $secret ) {
	return rest_url( 'maintenance-pilote/v1/mcp/' . $secret );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'maintenance-pilote/v1',
			'/mcp/(?P<cle>[A-Za-z0-9]{48})',
			array(
				'methods'             => array( 'GET', 'POST', 'DELETE' ),
				'callback'            => 'mp_mcp_handle',
				'permission_callback' => '__return_true', // Authentification vérifiée dans mp_mcp_handle().
			)
		);
	}
);

function mp_mcp_rpc_error( $id, $code, $message ) {
	return array(
		'jsonrpc' => '2.0',
		'id'      => $id,
		'error'   => array( 'code' => $code, 'message' => $message ),
	);
}

function mp_mcp_handle( WP_REST_Request $request ) {
	$mcp = get_option( 'mp_mcp' );
	$key = (string) $request->get_param( 'cle' );
	if ( ! is_array( $mcp ) || empty( $mcp['hash'] ) || ! hash_equals( $mcp['hash'], hash( 'sha256', $key ) ) ) {
		return new WP_REST_Response( array( 'error' => 'Introuvable.' ), 404 );
	}
	$user = get_userdata( (int) $mcp['user_id'] );
	if ( ! $user || ! user_can( $user, 'update_plugins' ) ) {
		return new WP_REST_Response( array( 'error' => 'Le compte associé au connecteur n\'a plus les droits nécessaires.' ), 403 );
	}
	wp_set_current_user( $user->ID );

	if ( 'POST' !== $request->get_method() ) {
		$r = new WP_REST_Response( array( 'error' => 'Méthode non prise en charge.' ), 405 );
		$r->header( 'Allow', 'POST' );
		return $r;
	}

	$body = json_decode( $request->get_body(), true );
	if ( ! is_array( $body ) ) {
		return new WP_REST_Response( mp_mcp_rpc_error( null, -32700, 'JSON invalide.' ), 400 );
	}
	$is_batch = array_keys( $body ) === range( 0, count( $body ) - 1 ) && ! empty( $body );
	$messages = $is_batch ? $body : array( $body );

	$responses = array();
	foreach ( $messages as $msg ) {
		$res = mp_mcp_dispatch( is_array( $msg ) ? $msg : array() );
		if ( null !== $res ) {
			$responses[] = $res;
		}
	}
	if ( empty( $responses ) ) {
		// Notifications uniquement : 202 sans contenu, comme l'exige le protocole.
		status_header( 202 );
		header( 'Content-Length: 0' );
		exit;
	}
	return new WP_REST_Response( $is_batch ? $responses : $responses[0], 200 );
}

function mp_mcp_dispatch( $msg ) {
	$has_id = array_key_exists( 'id', $msg );
	$id     = $has_id ? $msg['id'] : null;
	$method = isset( $msg['method'] ) ? (string) $msg['method'] : '';
	$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

	if ( ! $has_id ) {
		return null; // Notification (ex. notifications/initialized) : pas de réponse.
	}
	switch ( $method ) {
		case 'initialize':
			$supported = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );
			$asked     = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
			$result    = array(
				'protocolVersion' => in_array( $asked, $supported, true ) ? $asked : '2025-06-18',
				'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
				'serverInfo'      => array(
					'name'    => 'maintenance-pilote',
					'title'   => 'Maintenance Pilote – ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'version' => MP_VERSION,
				),
				'instructions'    => 'Actions de maintenance (mises à jour contrôlées avec retour arrière, vérification du site) et de publication d\'articles pour le site ' . home_url( '/' ) . '. Après chaque mise à jour, lancer maintenance-pilote-verifier-site.',
			);
			return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );

		case 'ping':
			return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass() );

		case 'tools/list':
			return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => array( 'tools' => mp_mcp_tools() ) );

		case 'tools/call':
			return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => mp_mcp_call( $params ) );

		default:
			return mp_mcp_rpc_error( $id, -32601, 'Méthode inconnue : ' . $method );
	}
}

function mp_mcp_tools() {
	$tools = array();
	foreach ( mp_ability_names() as $name ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			continue;
		}
		$schema = $ability->get_input_schema();
		if ( empty( $schema ) ) {
			$schema = array( 'type' => 'object', 'properties' => new stdClass() );
		} elseif ( isset( $schema['properties'] ) && empty( $schema['properties'] ) ) {
			$schema['properties'] = new stdClass();
		}
		$meta  = $ability->get_meta();
		$ann   = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$tools[] = array(
			'name'        => str_replace( '/', '-', $name ),
			'title'       => $ability->get_label(),
			'description' => $ability->get_description(),
			'inputSchema' => $schema,
			'annotations' => array(
				'title'           => $ability->get_label(),
				'readOnlyHint'    => ! empty( $ann['readonly'] ),
				'destructiveHint' => ! empty( $ann['destructive'] ),
				'idempotentHint'  => ! empty( $ann['idempotent'] ),
				'openWorldHint'   => false,
			),
		);
	}
	// Deux outils permanents : la liste vue par Claude ne change plus quand de nouvelles actions sont ajoutées.
	$tools[] = array(
		'name'        => 'maintenance-pilote-actions',
		'title'       => 'Actions disponibles',
		'description' => 'Liste toutes les actions Maintenance Pilote installées sur ce site, avec leur description et leurs paramètres. À consulter si une action attendue n\'apparaît pas comme outil : elle peut alors être lancée avec maintenance-pilote-executer.',
		'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
		'annotations' => array( 'title' => 'Actions disponibles', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
	);
	$tools[] = array(
		'name'        => 'maintenance-pilote-executer',
		'title'       => 'Exécuter une action',
		'description' => 'Exécute une action Maintenance Pilote par son nom (ex. « verifier-site » ou « maintenance-pilote/verifier-site ») avec ses paramètres. Donne accès à toutes les actions, y compris celles ajoutées après la connexion du connecteur.',
		'inputSchema' => array(
			'type'                 => 'object',
			'properties'           => array(
				'action'     => array( 'type' => 'string', 'description' => 'Nom de l\'action, tel que renvoyé par maintenance-pilote-actions.' ),
				'parametres' => array( 'type' => 'object', 'description' => 'Paramètres de l\'action (selon son schéma).' ),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		),
		'annotations' => array( 'title' => 'Exécuter une action', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false ),
	);
	return $tools;
}

function mp_resolve_ability_name( $action ) {
	$action = trim( (string) $action );
	$short  = preg_replace( '#^maintenance-pilote[/-]#', '', $action );
	foreach ( mp_ability_names() as $n ) {
		if ( $n === $action || str_replace( '/', '-', $n ) === $action || substr( $n, strlen( 'maintenance-pilote/' ) ) === $short ) {
			return $n;
		}
	}
	return null;
}

function mp_run_ability( $name, $args ) {
	$ability = $name && function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
	if ( ! $ability ) {
		return new WP_Error( 'mp_unknown', 'Action inconnue sur ce site (version ' . MP_VERSION . ').' );
	}
	$input = empty( $ability->get_input_schema() ) ? null : ( is_array( $args ) ? $args : array() );
	return $ability->execute( $input );
}

function mp_list_actions() {
	$out = array();
	foreach ( mp_ability_names() as $n ) {
		$a = function_exists( 'wp_get_ability' ) ? wp_get_ability( $n ) : null;
		if ( ! $a ) {
			continue;
		}
		$meta  = $a->get_meta();
		$out[] = array(
			'action'      => substr( $n, strlen( 'maintenance-pilote/' ) ),
			'titre'       => $a->get_label(),
			'description' => $a->get_description(),
			'parametres'  => $a->get_input_schema() ?: new stdClass(),
			'lecture_seule' => ! empty( $meta['annotations']['readonly'] ),
		);
	}
	return array( 'version' => MP_VERSION, 'actions' => $out );
}

function mp_mcp_call( $params ) {
	$tool = isset( $params['name'] ) ? (string) $params['name'] : '';
	$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
	if ( 'maintenance-pilote-actions' === $tool || 'maintenance-pilote-executer' === $tool ) {
		$result = 'maintenance-pilote-actions' === $tool
			? mp_list_actions()
			: mp_run_ability( mp_resolve_ability_name( $args['action'] ?? '' ), $args['parametres'] ?? array() );
		if ( is_wp_error( $result ) ) {
			return array( 'content' => array( array( 'type' => 'text', 'text' => $result->get_error_message() ) ), 'isError' => true );
		}
		return array( 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) ), 'isError' => false );
	}
	$name = null;
	foreach ( mp_ability_names() as $n ) {
		if ( str_replace( '/', '-', $n ) === $tool ) {
			$name = $n;
			break;
		}
	}
	$ability = $name && function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
	if ( ! $ability ) {
		return array(
			'content' => array( array( 'type' => 'text', 'text' => 'Outil inconnu : ' . $tool ) ),
			'isError' => true,
		);
	}
	// Sans schéma d'entrée, WordPress exige une entrée nulle.
	$input  = empty( $ability->get_input_schema() ) ? null : $args;
	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return array(
			'content' => array( array( 'type' => 'text', 'text' => $result->get_error_message() ) ),
			'isError' => true,
		);
	}
	return array(
		'content' => array(
			array(
				'type' => 'text',
				'text' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ),
			),
		),
		'isError' => false,
	);
}

/* -------------------------------------------------------------------------
 * Déclaration des abilities
 * ---------------------------------------------------------------------- */

add_action(
	'wp_abilities_api_categories_init',
	function () {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'maintenance-pilote',
				array(
					'label'       => 'Maintenance pilotée',
					'description' => 'Mises à jour contrôlées, retour arrière et vérification du site.',
				)
			);
		}
	}
);

add_action(
	'wp_abilities_api_init',
	function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$GLOBALS['mp_abilities_registered'] = true;

		$meta = function ( $readonly, $destructive, $idempotent ) {
			return array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => $readonly,
					'destructive' => $destructive,
					'idempotent'  => $idempotent,
				),
				'mcp'          => array( 'public' => true ),
			);
		};
		$can_plugins = function () {
			return current_user_can( 'update_plugins' );
		};

		wp_register_ability(
			'maintenance-pilote/etat',
			array(
				'label'               => 'État de maintenance',
				'description'         => 'Liste les mises à jour disponibles (WordPress, extensions, thèmes, traductions) et l\'état de la dernière sauvegarde. Lecture seule. Utiliser la valeur « fichier » d\'une extension pour la mettre à jour.',
				'category'            => 'maintenance-pilote',
				'execute_callback'    => 'mp_ability_etat',
				'permission_callback' => $can_plugins,
				'meta'                => $meta( true, false, true ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/sauvegardes',
			array(
				'label'               => 'Suivi des sauvegardes',
				'description'         => 'Historique des sauvegardes WPvivid (réussies et échouées, avec leurs dates), planification et prochaine sauvegarde, stockage distant, dernière erreur, et autres extensions de sauvegarde actives. Lecture seule.',
				'category'            => 'maintenance-pilote',
				'execute_callback'    => 'mp_ability_backups',
				'permission_callback' => $can_plugins,
				'meta'                => $meta( true, false, true ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/verifier-site',
			array(
				'label'               => 'Vérifier le site',
				'description'         => 'Charge la page d\'accueil et les pages indiquées, vérifie le code HTTP, la présence d\'erreurs PHP ou de pages incomplètes, et renvoie les lignes des journaux d\'erreurs des dernières 24 heures, avec le nombre d\'erreurs graves (erreurs fatales). Lecture seule.',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'urls' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'maxItems'    => 10,
							'description' => 'Adresses supplémentaires du même site à vérifier (facultatif).',
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_verifier',
				'permission_callback' => $can_plugins,
				'meta'                => $meta( true, false, true ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/mettre-a-jour-extension',
			array(
				'label'               => 'Mettre à jour une extension',
				'description'         => 'Met à jour UNE extension après en avoir fait une copie de sécurité, puis la réactive si elle était active. Vérifier le site après chaque appel.',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'extension' => array(
							'type'        => 'string',
							'description' => 'Valeur « fichier » de l\'extension, par exemple elementor/elementor.php.',
						),
					),
					'required'             => array( 'extension' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_update_plugin',
				'permission_callback' => $can_plugins,
				'meta'                => $meta( false, false, false ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/activer-extension',
			array(
				'label'               => 'Activer des extensions',
				'description'         => 'Active une ou plusieurs extensions déjà installées (par exemple pour rétablir des extensions restées désactivées). Ne fait rien sur une extension déjà active.',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'extension'  => array( 'type' => 'string', 'description' => 'Valeur « fichier » d\'une extension.' ),
						'extensions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 40, 'description' => 'Plusieurs valeurs « fichier ».' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_activate_plugin',
				'permission_callback' => function () {
					return current_user_can( 'activate_plugins' );
				},
				'meta'                => $meta( false, false, true ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/mettre-a-jour-theme',
			array(
				'label'               => 'Mettre à jour un thème',
				'description'         => 'Met à jour UN thème après en avoir fait une copie de sécurité. Vérifier le site après l\'appel.',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'theme' => array(
							'type'        => 'string',
							'description' => 'Identifiant du thème (valeur « theme » renvoyée par maintenance-pilote/etat).',
						),
					),
					'required'             => array( 'theme' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_update_theme',
				'permission_callback' => function () {
					return current_user_can( 'update_themes' );
				},
				'meta'                => $meta( false, false, false ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/mettre-a-jour-traductions',
			array(
				'label'               => 'Mettre à jour les traductions',
				'description'         => 'Installe toutes les mises à jour de traductions en attente.',
				'category'            => 'maintenance-pilote',
				'execute_callback'    => 'mp_ability_update_translations',
				'permission_callback' => $can_plugins,
				'meta'                => $meta( false, false, true ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/mettre-a-jour-wordpress',
			array(
				'label'               => 'Mettre à jour WordPress',
				'description'         => 'Met à jour le cœur de WordPress. Pas de retour arrière automatique : ne lancer que si une sauvegarde complète récente a réussi.',
				'category'            => 'maintenance-pilote',
				'execute_callback'    => 'mp_ability_update_core',
				'permission_callback' => function () {
					return current_user_can( 'update_core' );
				},
				'meta'                => $meta( false, true, false ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/restaurer',
			array(
				'label'               => 'Revenir à la version précédente',
				'description'         => 'Remet en place la dernière copie de sécurité d\'une extension ou d\'un thème, prise juste avant sa mise à jour.',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'type'    => array(
							'type' => 'string',
							'enum' => array( 'extension', 'theme' ),
						),
						'element' => array(
							'type'        => 'string',
							'description' => 'Pour une extension : sa valeur « fichier ». Pour un thème : son identifiant.',
						),
					),
					'required'             => array( 'type', 'element' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_restore',
				'permission_callback' => $can_plugins,
				'meta'                => $meta( false, true, false ),
			)
		);
		$img_props = array(
			'nom_fichier' => array( 'type' => 'string', 'description' => 'Nom de fichier SEO, sans extension (ex. randonnee-desert-maroc).' ),
			'titre'       => array( 'type' => 'string', 'description' => 'Titre de l\'image dans la médiathèque.' ),
			'alt'         => array( 'type' => 'string', 'description' => 'Texte alternatif optimisé SEO.' ),
			'legende'     => array( 'type' => 'string', 'description' => 'Légende.' ),
			'description' => array( 'type' => 'string', 'description' => 'Description.' ),
			'article_id'  => array( 'type' => 'integer', 'description' => 'Article auquel rattacher l\'image (facultatif).' ),
		);
		$can_write = function () {
			return current_user_can( 'publish_posts' ) && current_user_can( 'upload_files' );
		};

		wp_register_ability(
			'maintenance-pilote/contexte-articles',
			array(
				'label'               => 'Contexte pour les articles',
				'description'         => 'Liste les derniers articles (titres, slugs, catégories), les catégories et étiquettes existantes, l\'extension SEO active et la disponibilité de la génération d\'images. Lecture seule. À appeler avant de rédiger pour éviter les doublons.',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'nombre' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_articles_context',
				'permission_callback' => $can_write,
				'meta'                => $meta( true, false, true ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/generer-image',
			array(
				'label'               => 'Générer une image',
				'description'         => 'Génère une image avec l\'API Mammouth (GPT Image), l\'optimise (1600 px, WebP) et l\'ajoute à la médiathèque avec ses textes SEO. Renvoie image_id. L\'image ne transite pas par Claude.',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array( 'prompt' => array( 'type' => 'string', 'description' => 'Description détaillée de l\'image à générer (style photographie réaliste, format paysage).' ) ),
						$img_props
					),
					'required'             => array( 'prompt', 'alt' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_generate_image',
				'permission_callback' => $can_write,
				'meta'                => $meta( false, false, false ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/importer-image',
			array(
				'label'               => 'Importer une image de banque',
				'description'         => 'Télécharge une image depuis Unsplash, Pexels ou Pixabay, l\'optimise et l\'ajoute à la médiathèque avec ses textes SEO. Renvoie image_id.',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array( 'url' => array( 'type' => 'string', 'description' => 'Adresse directe de l\'image (images.unsplash.com, images.pexels.com, cdn.pixabay.com).' ) ),
						$img_props
					),
					'required'             => array( 'url', 'alt' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_import_image',
				'permission_callback' => $can_write,
				'meta'                => $meta( false, false, false ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/publier-article',
			array(
				'label'               => 'Publier un article',
				'description'         => 'Crée un nouvel article (jamais de modification d\'un article existant) avec titre, contenu HTML, slug, extrait, 1 à 3 catégories existantes, étiquettes existantes, image mise en avant et méta-description SEO (Yoast, Rank Math, SEOPress, The SEO Framework ou AIOSEO).',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'titre'            => array( 'type' => 'string' ),
						'contenu'          => array( 'type' => 'string', 'description' => 'Contenu HTML (h2, h3, p, ul, strong...). Pas de h1.' ),
						'slug'             => array( 'type' => 'string' ),
						'extrait'          => array( 'type' => 'string' ),
						'meta_description' => array( 'type' => 'string' ),
						'titre_seo'        => array( 'type' => 'string' ),
						'categorie'        => array( 'type' => 'string', 'description' => 'Une catégorie existante (nom ou identifiant). Préférer « categories ».' ),
						'categories'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 3, 'description' => '1 à 3 catégories EXISTANTES (identifiants de préférence). Jamais de création.' ),
						'etiquettes'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 8, 'description' => 'Étiquettes EXISTANTES (noms ou identifiants). Les inexistantes sont ignorées, sauf si creer_etiquettes = true.' ),
						'creer_etiquettes' => array( 'type' => 'boolean', 'description' => 'Autoriser la création d\'étiquettes. Uniquement si le prompt du site l\'autorise explicitement. Par défaut : false.' ),
						'image_id'         => array( 'type' => 'integer', 'description' => 'Image mise en avant.' ),
						'image_meta'       => array(
							'type'                 => 'object',
							'description'          => 'Textes SEO à appliquer à l\'image mise en avant (facultatif).',
							'properties'           => array(
								'alt'         => array( 'type' => 'string' ),
								'titre'       => array( 'type' => 'string' ),
								'legende'     => array( 'type' => 'string' ),
								'description' => array( 'type' => 'string' ),
							),
							'additionalProperties' => false,
						),
						'images_texte'     => array(
							'type'        => 'array',
							'maxItems'    => 12,
							'description' => 'Images à insérer dans le texte. Placer le repère [[image:N]] (N = position dans cette liste, à partir de 1) seul dans un paragraphe à l\'endroit voulu ; les images sans repère sont placées automatiquement.',
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'image_id'    => array( 'type' => 'integer' ),
									'alt'         => array( 'type' => 'string' ),
									'titre'       => array( 'type' => 'string' ),
									'legende'     => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
								),
								'required'             => array( 'image_id' ),
								'additionalProperties' => false,
							),
						),
						'statut'           => array( 'type' => 'string', 'enum' => array( 'publish', 'draft' ) ),
					),
					'required'             => array( 'titre', 'contenu' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_publish_article',
				'permission_callback' => $can_write,
				'meta'                => $meta( false, false, false ),
			)
		);
		wp_register_ability(
			'maintenance-pilote/televerser-image',
			array(
				'label'               => 'Téléverser une image',
				'description'         => 'Ajoute à la médiathèque une image envoyée en base64 (JPEG, PNG ou WebP, 16 Mo maximum), optimisée en 1600 px. Renvoie image_id et url.',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array( 'donnees_base64' => array( 'type' => 'string', 'description' => 'Contenu de l\'image encodé en base64.' ) ),
						$img_props
					),
					'required'             => array( 'donnees_base64' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_upload_image',
				'permission_callback' => $can_write,
				'meta'                => $meta( false, false, false ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/publier-brouillon',
			array(
				'label'               => 'Publier un brouillon',
				'description'         => 'Publie un article existant qui est en brouillon ou en attente. Ne modifie rien d\'autre.',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'article_id' => array( 'type' => 'integer' ) ),
					'required'             => array( 'article_id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_publish_draft',
				'permission_callback' => $can_write,
				'meta'                => $meta( false, false, true ),
			)
		);

		wp_register_ability(
			'maintenance-pilote/modifier-seo-article',
			array(
				'label'               => 'Modifier le SEO d\'un article',
				'description'         => 'Modifie uniquement le titre SEO et/ou la méta-description d\'un article, dans l\'extension SEO du site.',
				'category'            => 'maintenance-pilote',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'article_id'       => array( 'type' => 'integer' ),
						'titre_seo'        => array( 'type' => 'string' ),
						'meta_description' => array( 'type' => 'string' ),
					),
					'required'             => array( 'article_id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'mp_ability_update_seo',
				'permission_callback' => $can_write,
				'meta'                => $meta( false, false, true ),
			)
		);
	},
	5
);

/*
 * Ajoute ces actions à la liste des outils du serveur MCP d'EMCP Tools
 * (connecteur déjà branché sur Claude), via son filtre public.
 */
add_filter(
	'emcp_tools_ability_names',
	function ( $names ) {
		$names = is_array( $names ) ? $names : array();
		$ours  = mp_ability_names();
		foreach ( $ours as $name ) {
			if ( ! empty( $GLOBALS['mp_abilities_registered'] ) && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}
		return $names;
	},
	20
);

register_activation_hook( __FILE__, 'mp_protect_snap_dir' );
