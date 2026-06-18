<?php
/**
 * Plugin Name: Weenat API Integration
 * Description: Muestra datos de dispositivos y mediciones de la API Weenat mediante shortcodes.
 * Version: 1.0.0
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
	define( 'WEENAT_API_BASE', 'https://api.weenat.com/v3' );
}

// ID de la estación por defecto.
if ( ! defined( 'WEENAT_DEFAULT_DEVICE_ID' ) ) {
	define( 'WEENAT_DEFAULT_DEVICE_ID', 47032 );
}

// ─────────────────────────────────────────────────────────────────
// Función de llamada HTTP centralizada
// ─────────────────────────────────────────────────────────────────

/**
 * Realiza una petición GET autenticada a la API Weenat.
 *
 * @param string $endpoint  Ruta relativa, p. ej. '/devices/' o '/devices/47032/measurements/'.
 * @param array  $query     Parámetros de consulta opcionales (clave => valor).
 * @return array|WP_Error   Array descodificado o WP_Error en caso de fallo.
 */
function weenat_api_get( $endpoint, $query = [] ) {
	$api_key = WEENAT_API_KEY;

	if ( empty( $api_key ) ) {
		return new WP_Error(
			'weenat_no_key',
			__( 'La clave de la API Weenat no está configurada. Define WEENAT_API_KEY en wp-config.php.', 'weenat-api' )
		);
	}

	$url = rtrim( WEENAT_API_BASE, '/' ) . '/' . ltrim( $endpoint, '/' );

	if ( ! empty( $query ) ) {
		$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
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
				/* translators: 1: HTTP status code, 2: response body */
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
// ─────────────────────────────────────────────────────────────────

/**
 * Muestra la lista de dispositivos Weenat registrados.
 *
 * Uso: [weenat_devices]
 */
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
// Shortcode: [weenat_measurements device_id="47032" metrics="T,U,RR" days="1"]
// ─────────────────────────────────────────────────────────────────

/**
 * Muestra las mediciones de un dispositivo Weenat.
 *
 * Parámetros del shortcode:
 *  - device_id  (opcional)  : ID numérico del dispositivo. Por defecto WEENAT_DEFAULT_DEVICE_ID (47032).
 *  - metrics    (opcional)  : métricas separadas por coma, p. ej. "T,U,RR".
 *                             Si se omite se usan todas las disponibles.
 *  - days       (opcional)  : número de días hacia atrás a consultar (por defecto 1).
 *  - step       (opcional)  : resolución temporal en minutos (por defecto 60).
 *
 * Uso: [weenat_measurements]
 * Uso: [weenat_measurements metrics="T,U" days="2"]
 */
function weenat_shortcode_measurements( $atts ) {
	$atts = shortcode_atts(
		[
			'device_id' => WEENAT_DEFAULT_DEVICE_ID,
			'metrics'   => '',
			'days'      => 1,
			'step'      => 60,
		],
		$atts,
		'weenat_measurements'
	);

	$device_id = absint( $atts['device_id'] );

	if ( ! $device_id ) {
		return '<p class="weenat-error">' . esc_html__( 'Indica el atributo device_id en el shortcode.', 'weenat-api' ) . '</p>';
	}

	// Calcula el rango de fechas en UTC.
	$days     = max( 1, absint( $atts['days'] ) );
	$step     = max( 1, absint( $atts['step'] ) );
	$end_ts   = current_time( 'timestamp', true ); // UTC
	$start_ts = $end_ts - ( $days * DAY_IN_SECONDS );

	// La API Weenat espera fechas ISO 8601 (p. ej. 2024-06-01T00:00:00Z).
	$start_date = gmdate( 'Y-m-d\TH:i:s\Z', $start_ts );
	$end_date   = gmdate( 'Y-m-d\TH:i:s\Z', $end_ts );

	// Construye los parámetros de consulta.
	$query = [
		'start_date' => $start_date,
		'end_date'   => $end_date,
		'step'       => $step,
	];

	if ( ! empty( $atts['metrics'] ) ) {
		$query['metrics'] = $atts['metrics'];
	}

	$endpoint = '/devices/' . $device_id . '/measurements/';
	$data     = weenat_api_get( $endpoint, $query );

	if ( is_wp_error( $data ) ) {
		return '<p class="weenat-error">' . esc_html( $data->get_error_message() ) . '</p>';
	}

	// La API puede devolver los datos bajo distintas claves según la versión.
	// Se admiten estructuras: { "results": [...] }, { "data": [...] } o array raíz.
	$measurements = [];
	if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
		$measurements = $data['results'];
	} elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
		$measurements = $data['data'];
	} elseif ( is_array( $data ) && ! empty( $data ) ) {
		// Algunos endpoints devuelven directamente el array de mediciones.
		$first = reset( $data );
		if ( is_array( $first ) ) {
			$measurements = $data;
		}
	}

	if ( empty( $measurements ) ) {
		return '<p class="weenat-empty">' . esc_html__( 'Sin datos disponibles para el período solicitado.', 'weenat-api' ) . '</p>';
	}

	// Determina las columnas a partir de las claves de la primera medición.
	$first_row = reset( $measurements );
	$columns   = array_keys( $first_row );

	ob_start();
	?>
	<div class="weenat-measurements">
		<p class="weenat-period">
			<?php
			printf(
				/* translators: 1: start date, 2: end date */
				esc_html__( 'Período: %1$s — %2$s (UTC)', 'weenat-api' ),
				esc_html( $start_date ),
				esc_html( $end_date )
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
// Estilos básicos (encolados solo cuando hay shortcodes en la página)
// ─────────────────────────────────────────────────────────────────

function weenat_enqueue_styles() {
	wp_enqueue_style(
		'weenat-api',
		plugin_dir_url( __FILE__ ) . 'weenat-api.css',
		[],
		'1.0.0'
	);
}
add_action( 'wp_enqueue_scripts', 'weenat_enqueue_styles' );
