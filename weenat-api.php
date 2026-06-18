<?php
/**
 * Plugin Name: Weenat API Integration
 * Description: Muestra datos de dispositivos y mediciones de la API Weenat mediante shortcodes.
 * Version: 1.3.0
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
//   47032 — Anemómetro              (DD, DXY, FF, FXY)  → timespan raw/hour/day
//   47025 — Estación meteo          (RR, T, U)           → sin datos reales
//   75900 — Estación virtual (SMV)  (RR, T, U, THI)      → mínimo timespan=hour
//   75901 — Estación virtual (SMV)  (RR, T, U, THI)      → mínimo timespan=hour
if ( ! defined( 'WEENAT_DEFAULT_DEVICE_ID' ) ) {
	define( 'WEENAT_DEFAULT_DEVICE_ID', 47032 );
}

// ─────────────────────────────────────────────────────────────────
// Etiquetas, iconos y unidades de cada métrica
// ─────────────────────────────────────────────────────────────────

function weenat_metric_info() {
	return [
		'T'   => [ 'label' => 'Temperatura',         'icon' => '🌡️', 'unit' => '°C'  ],
		'U'   => [ 'label' => 'Humedad',              'icon' => '💧', 'unit' => '%'   ],
		'RR'  => [ 'label' => 'Precipitación',        'icon' => '🌧️', 'unit' => 'mm'  ],
		'FF'  => [ 'label' => 'Vel. viento',          'icon' => '💨', 'unit' => 'm/s' ],
		'FXY' => [ 'label' => 'Racha máx.',           'icon' => '🌬️', 'unit' => 'm/s' ],
		'DD'  => [ 'label' => 'Dir. viento',          'icon' => '🧭', 'unit' => '°'   ],
		'DXY' => [ 'label' => 'Dir. racha máx.',      'icon' => '🧭', 'unit' => '°'   ],
		'THI' => [ 'label' => 'Índice calor-humedad', 'icon' => '🌡️', 'unit' => ''    ],
	];
}

// ─────────────────────────────────────────────────────────────────
// Estilos inline (se inyectan una sola vez en el <head>)
// ─────────────────────────────────────────────────────────────────

function weenat_inline_styles() {
	?>
	<style id="weenat-api-styles">
		/* ── Tarjetas: [weenat_current] ────────────────────────── */
		.weenat-current {
			margin: 1.5em 0;
			font-family: inherit;
		}
		.weenat-current__title {
			font-size: 1.3em;
			font-weight: 700;
			margin-bottom: 0.25em;
			color: #1a1a2e;
		}
		.weenat-current__updated {
			font-size: 0.85em;
			color: #666;
			margin-bottom: 1em;
		}
		.weenat-current__grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
			gap: 1em;
		}
		.weenat-card {
			background: #ffffff;
			border: 1px solid #e0e0e0;
			border-radius: 12px;
			padding: 1.2em 1em;
			text-align: center;
			box-shadow: 0 2px 8px rgba(0,0,0,0.07);
			transition: transform 0.15s ease, box-shadow 0.15s ease;
			display: flex;
			flex-direction: column;
			align-items: center;
			gap: 0.4em;
		}
		.weenat-card:hover {
			transform: translateY(-3px);
			box-shadow: 0 6px 16px rgba(0,0,0,0.12);
		}
		.weenat-card__icon {
			font-size: 2em;
			line-height: 1;
		}
		.weenat-card__label {
			font-size: 0.78em;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			color: #888;
		}
		.weenat-card__value {
			font-size: 1.8em;
			font-weight: 700;
			color: #1a1a2e;
			line-height: 1.1;
		}
		.weenat-card__unit {
			font-size: 0.5em;
			font-weight: 400;
			color: #aaa;
			margin-left: 0.2em;
			vertical-align: super;
		}

		/* ── Tabla: [weenat_devices] y [weenat_measurements] ───── */
		.weenat-devices,
		.weenat-measurements {
			overflow-x: auto;
			margin: 1.5em 0;
		}
		.weenat-table {
			width: 100%;
			border-collapse: collapse;
			font-size: 0.9em;
		}
		.weenat-table th,
		.weenat-table td {
			padding: 0.5em 0.75em;
			border: 1px solid #ddd;
			text-align: left;
			vertical-align: top;
		}
		.weenat-table thead th {
			background: #f5f5f5;
			font-weight: 600;
		}
		.weenat-table tbody tr:nth-child(even) {
			background: #fafafa;
		}

		/* ── Estados ────────────────────────────────────────────── */
		.weenat-error {
			color: #c0392b;
			font-weight: bold;
		}
		.weenat-empty,
		.weenat-period {
			font-size: 0.85em;
			color: #666;
			margin-bottom: 0.5em;
		}
		.weenat-empty { font-style: italic; }

		/* ── Responsive ─────────────────────────────────────────── */
		@media (max-width: 480px) {
			.weenat-current__grid {
				grid-template-columns: repeat(2, 1fr);
			}
			.weenat-card__value {
				font-size: 1.4em;
			}
		}
	</style>
	<?php
}
add_action( 'wp_head', 'weenat_inline_styles' );

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
// Detecta si un dispositivo es estación virtual (SMV)
//
// Las estaciones SMV no admiten timespan=raw, mínimo es hour.
// Se cachea el resultado en un transient para no llamar a la API
// en cada carga de página.
// ─────────────────────────────────────────────────────────────────

function weenat_device_is_virtual( $device_id ) {
	$cache_key = 'weenat_device_model_' . $device_id;
	$model     = get_transient( $cache_key );

	if ( false === $model ) {
		$device = weenat_api_get( '/devices/' . $device_id . '/' );
		$model  = ( ! is_wp_error( $device ) && isset( $device['model'] ) )
			? $device['model']
			: '';
		// Cachea durante 24 horas (el modelo no cambia).
		set_transient( $cache_key, $model, DAY_IN_SECONDS );
	}

	return strtoupper( $model ) === 'SMV';
}

// ─────────────────────────────────────────────────────────────────
// Shortcode: [weenat_current]
//
// Muestra la ÚLTIMA medición como tarjetas visuales.
// Detecta automáticamente si el dispositivo es SMV y usa
// timespan=hour en ese caso (raw no está disponible para SMV).
//
// Parámetros:
//   device_id : ID del dispositivo. Por defecto 47032 (Anemómetro).
//               Usa 75900 o 75901 para estaciones virtuales (T, U, RR).
//   title     : Título opcional sobre las tarjetas.
//
// Ejemplos:
//   [weenat_current]
//   [weenat_current device_id="75900" title="Estación meteorológica"]
// ─────────────────────────────────────────────────────────────────

function weenat_shortcode_current( $atts ) {
	$atts = shortcode_atts(
		[
			'device_id' => WEENAT_DEFAULT_DEVICE_ID,
			'title'     => '',
		],
		$atts,
		'weenat_current'
	);

	$device_id = absint( $atts['device_id'] );

	if ( ! $device_id ) {
		return '<p class="weenat-error">' . esc_html__( 'Indica el atributo device_id en el shortcode.', 'weenat-api' ) . '</p>';
	}

	// Las estaciones virtuales (SMV) solo admiten timespan=hour como mínimo.
	// Las demás usan raw para obtener el dato más reciente posible.
	$is_virtual = weenat_device_is_virtual( $device_id );
	$timespan   = $is_virtual ? 'hour' : 'raw';

	$end_ts   = current_time( 'timestamp', true );
	// Para raw pedimos las últimas 3 horas; para hour las últimas 25 (garantiza al menos 1 registro).
	$start_ts = $is_virtual
		? $end_ts - ( 25 * HOUR_IN_SECONDS )
		: $end_ts - ( 3 * HOUR_IN_SECONDS );

	$query = [
		'timespan' => $timespan,
		'start'    => gmdate( 'Y-m-d\TH:i:s\Z', $start_ts ),
		'end'      => gmdate( 'Y-m-d\TH:i:s\Z', $end_ts ),
	];

	$data = weenat_api_get( '/data/devices/' . $device_id . '/', $query );

	if ( is_wp_error( $data ) ) {
		return '<p class="weenat-error">' . esc_html( $data->get_error_message() ) . '</p>';
	}

	$measurements = [];
	if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
		$measurements = $data['results'];
	} elseif ( is_array( $data ) && ! empty( $data ) && isset( $data[0] ) ) {
		$measurements = $data;
	}

	if ( empty( $measurements ) ) {
		return '<p class="weenat-empty">' . esc_html__( 'Sin datos disponibles.', 'weenat-api' ) . '</p>';
	}

	// Toma la última fila (dato más reciente).
	$latest   = end( $measurements );
	$metrics  = weenat_metric_info();
	$datetime = isset( $latest['datetime'] ) ? $latest['datetime'] : '';

	// Convierte datetime UTC a hora local de Madrid.
	$fecha_local = '';
	if ( $datetime ) {
		try {
			$dt = new DateTime( $datetime, new DateTimeZone( 'UTC' ) );
			$dt->setTimezone( new DateTimeZone( 'Europe/Madrid' ) );
			$fecha_local = $dt->format( 'd/m/Y H:i' );
		} catch ( Exception $e ) {
			$fecha_local = $datetime;
		}
	}

	ob_start();
	?>
	<div class="weenat-current">
		<?php if ( ! empty( $atts['title'] ) ) : ?>
			<h3 class="weenat-current__title"><?php echo esc_html( $atts['title'] ); ?></h3>
		<?php endif; ?>
		<?php if ( $fecha_local ) : ?>
			<p class="weenat-current__updated">
				<?php esc_html_e( 'Última actualización:', 'weenat-api' ); ?>
				<strong><?php echo esc_html( $fecha_local ); ?></strong>
			</p>
		<?php endif; ?>
		<div class="weenat-current__grid">
			<?php foreach ( $metrics as $key => $info ) : ?>
				<?php if ( ! array_key_exists( $key, $latest ) ) continue; ?>
				<?php
				$value = $latest[ $key ];
				if ( is_numeric( $value ) ) {
					$value = number_format( (float) $value, 1, ',', '.' );
				} elseif ( is_null( $value ) ) {
					$value = '—';
				}
				?>
				<div class="weenat-card">
					<span class="weenat-card__icon"><?php echo $info['icon']; ?></span>
					<span class="weenat-card__label"><?php echo esc_html( $info['label'] ); ?></span>
					<span class="weenat-card__value">
						<?php echo esc_html( $value ); ?>
						<?php if ( $value !== '—' ) : ?>
							<span class="weenat-card__unit"><?php echo esc_html( $info['unit'] ); ?></span>
						<?php endif; ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'weenat_current', 'weenat_shortcode_current' );

// ─────────────────────────────────────────────────────────────────
// Shortcode: [weenat_devices]
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
//   device_id : ID del dispositivo. Por defecto 47032 (Anemómetro).
//   timespan  : Resolución: raw, hour (defecto), day.
//   days      : Días hacia atrás (defecto 1, máx 35 con hour).
//
// Ejemplos:
//   [weenat_measurements]
//   [weenat_measurements device_id="75900" timespan="hour" days="7"]
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
	$end_ts   = current_time( 'timestamp', true );
	$start_ts = $end_ts - ( $days * DAY_IN_SECONDS );

	$start = gmdate( 'Y-m-d\TH:i:s\Z', $start_ts );
	$end   = gmdate( 'Y-m-d\TH:i:s\Z', $end_ts );

	$query = [
		'timespan' => sanitize_text_field( $atts['timespan'] ),
		'start'    => $start,
		'end'      => $end,
	];

	$data = weenat_api_get( '/data/devices/' . $device_id . '/', $query );

	if ( is_wp_error( $data ) ) {
		return '<p class="weenat-error">' . esc_html( $data->get_error_message() ) . '</p>';
	}

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
