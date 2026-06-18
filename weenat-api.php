<?php
/**
 * Plugin Name: Weenat API Integration
 * Description: Muestra datos de dispositivos y mediciones de la API Weenat mediante shortcodes.
 * Version: 1.1.0
 * Author: Ayuntamiento Farlete
 * Text Domain: weenat-api
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────
// Configuración
// ─────────────────────────────────────────────────────────────────

if ( ! defined( 'WEENAT_API_KEY' ) ) {
	define( 'WEENAT_API_KEY', 'CvK5lHbwTQ5jcP6tkVpr' );
}

if ( ! defined( 'WEENAT_API_BASE' ) ) {
	define( 'WEENAT_API_BASE', 'https://api-prod.weenat.com/v3' );
}

// Estaciones disponibles:
//   47032 — Anemómetro        (DD, DXY, FF, FXY)
//   47025 — Estación meteo    (RR, T, U)
if ( ! defined( 'WEENAT_DEFAULT_DEVICE_ID' ) ) {
	define( 'WEENAT_DEFAULT_DEVICE_ID', 47032 );
}

// ─────────────────────────────────────────────────────────────────
// Función de llamada HTTP centralizada
// ─────────────────────────────────────────────────────────────────

/**
 * Realiza una petición GET autenticada a la API Weenat.
 *
 * @param string $endpoint  Ruta relativa, p. ej. '/devices/' o '/data/devices/47032/'.
 * @param array  $query     Parámetros de consulta opcionales (clave => valor).
 * @return array|WP_Error   Array descodificado o WP_Error en caso de fallo.
 */
function weenat_api_get( $endpoint, $query = [] ) {
	$api_key = WEENAT_API_KEY;

	if ( empty( $api_key ) ) {
		return new WP_Error(
			'weenat_no_key',
			__( 'La clave de la API Weenat no está configurada.', 'weenat-api' )
		);
	}

	$url = rtrim( WEENAT_API_BASE, '/' ) . '/' . ltrim( $endpoint, '/' );

	if ( ! empty( $query ) ) {
		$url = add_query_arg( $query, $url );
	}

	$response = wp_remote_get(
		$url,
		[
			'headers' => [
				'Authorization' => 'Weenat-Api-Key ' . $api_key,
				'Accept'        => 'application/json',
			],
			'timeout' => 15,
		]
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = wp_remote_retrieve_response_code( $response );
	$body   = wp_remote_retrieve_body( $response );

	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error(
			'weenat_http_error',
			sprintf(
				__( 'La API Weenat devolvió el código %1$d: %2$s', 'weenat-api' ),
				$status,
				esc_html( $body )
			)
		);
	}

	$data = json_decode( $body, true );

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		return new WP_Error(
			'weenat_json_error',
			__( 'No se pudo decodificar la respuesta JSON de la API Weenat.', 'weenat-api' )
		);
	}

	return $data;
}

// ─────────────────────────────────────────────────────────────────
// Shortcode: [weenat_devices]
// Muestra la lista de todas las estaciones.
// ─────────────────────────────────────────────────────────────────

function weenat_shortcode_devices( $atts ) {
	$data = weenat_api_get( '/devices/' );

	if ( is_wp_error( $data ) ) {
		return '<p class="weenat-error">' . esc_html( $data->get_error_message() ) . '</p>';
	}

	$results = isset( $data['results'] ) ? $data['results'] : [];

	if ( empty( $results ) ) {
		return '<p class="weenat-empty">' . esc_html__( 'Sin datos disponibles.', 'weenat-api' ) . '</p>';
	}

	ob_start();
	?>
	<div class="weenat-devices">
		<table class="weenat-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'weenat-api' ); ?></th>
					<th><?php esc_html_e( 'Número de serie', 'weenat-api' ); ?></th>
					<th><?php esc_html_e( 'Modelo', 'weenat-api' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'weenat-api' ); ?></th>
					<th><?php esc_html_e( 'Métricas disponibles', 'weenat-api' ); ?></th>
					<th><?php esc_html_e( 'Ubicación (lat, lon)', 'weenat-api' ); ?></th>
					<th><?php esc_html_e( 'Zona horaria', 'weenat-api' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $device ) : ?>
					<tr>
						<td><?php echo esc_html( $device['id'] ); ?></td>
						<td><?php echo esc_html( $device['serial_number'] ); ?></td>
						<td><?php echo esc_html( $device['model'] ); ?></td>
						<td><?php echo esc_html( $device['model_label'] ); ?></td>
						<td><?php echo esc_html( implode( ', ', $device['available_metrics'] ?? [] ) ); ?></td>
						<td>
							<?php
							if ( ! empty( $device['location'] ) && is_array( $device['location'] ) ) {
								echo esc_html( $device['location'][0] . ', ' . $device['location'][1] );
							}
							?>
						</td>
						<td><?php echo esc_html( $device['timezone'] ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'weenat_devices', 'weenat_shortcode_devices' );

// ─────────────────────────────────────────────────────────────────
// Shortcode: [weenat_measurements]
//
// Parámetros:
//   device_id  : ID del dispositivo. Por defecto 47032 (Anemómetro).
//                Usa 47025 para la estación meteorológica (RR, T, U).
//   timespan   : Resolución de datos: raw, hour (defecto), day.
//   days       : Días hacia atrás a consultar (defecto 1, máx 35 con hour).
//
// Ejemplos:
//   [weenat_measurements]
//   [weenat_measurements device_id="47025" timespan="hour" days="7"]
// ─────────────────────────────────────────────────────────────────

function weenat_shortcode_measurements( $atts ) {
	$atts = shortcode_atts(
		[
			'device_id' => WEENAT_DEFAULT_DEVICE_ID,
			'timespan'  => 'hour',
			'days'      => 1,
		],
		$atts,
		'weenat_measurements'
	);

	$device_id = absint( $atts['device_id'] );

	if ( ! $device_id ) {
		return '<p class="weenat-error">' . esc_html__( 'Indica el atributo device_id en el shortcode.', 'weenat-api' ) . '</p>';
	}

	$days     = max( 1, absint( $atts['days'] ) );
	$end_ts   = current_time( 'timestamp', true ); // UTC
	$start_ts = $end_ts - ( $days * DAY_IN_SECONDS );

	$start = gmdate( 'Y-m-d\TH:i:s\Z', $start_ts );
	$end   = gmdate( 'Y-m-d\TH:i:s\Z', $end_ts );

	$query = [
		'timespan' => sanitize_text_field( $atts['timespan'] ),
		'start'    => $start,
		'end'      => $end,
	];

	$endpoint = '/data/devices/' . $device_id . '/';
	$data     = weenat_api_get( $endpoint, $query );

	if ( is_wp_error( $data ) ) {
		return '<p class="weenat-error">' . esc_html( $data->get_error_message() ) . '</p>';
	}

	// La API devuelve directamente un array de objetos: [{"datetime":...,"DD":...}, ...]
	$measurements = [];
	if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
		$measurements = $data['results'];
	} elseif ( is_array( $data ) && ! empty( $data ) && isset( $data[0] ) ) {
		$measurements = $data;
	}

	if ( empty( $measurements ) ) {
		return '<p class="weenat-empty">' . esc_html__( 'Sin datos disponibles para el período solicitado.', 'weenat-api' ) . '</p>';
	}

	$first_row = reset( $measurements );
	$columns   = array_keys( $first_row );

	ob_start();
	?>
	<div class="weenat-measurements">
		<p class="weenat-period">
			<?php
			printf(
				esc_html__( 'Período: %1$s — %2$s (UTC)', 'weenat-api' ),
				esc_html( $start ),
				esc_html( $end )
			);
			?>
		</p>
		<table class="weenat-table">
			<thead>
				<tr>
					<?php foreach ( $columns as $col ) : ?>
						<th><?php echo esc_html( $col ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $measurements as $row ) : ?>
					<tr>
						<?php foreach ( $columns as $col ) : ?>
							<td><?php echo esc_html( isset( $row[ $col ] ) ? $row[ $col ] : '—' ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'weenat_measurements', 'weenat_shortcode_measurements' );

// ─────────────────────────────────────────────────────────────────
// Estilos básicos
// ─────────────────────────────────────────────────────────────────

function weenat_enqueue_styles() {
	wp_enqueue_style(
		'weenat-api',
		plugin_dir_url( __FILE__ ) . 'weenat-api.css',
		[],
		'1.1.0'
	);
}
add_action( 'wp_enqueue_scripts', 'weenat_enqueue_styles' );
