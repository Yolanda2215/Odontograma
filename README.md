# Weenat API Integration — Plugin WordPress

Plugin PHP para integrar la API Weenat en WordPress mediante shortcodes.

---

## Instalación

1. Copia los dos archivos a una carpeta nueva dentro de `wp-content/plugins/`, por ejemplo:
   ```
   wp-content/plugins/weenat-api/
   ├── weenat-api.php
   └── weenat-api.css
   ```
2. Activa el plugin desde **Plugins → Plugins instalados** en el panel de WordPress.
3. Añade tu clave de API en `wp-config.php` (antes de la línea `/* That's all, stop editing! */`):
   ```php
   define( 'WEENAT_API_KEY', 'TU_CLAVE_AQUI' );
   ```

---

## Shortcodes disponibles

### `[weenat_devices]`

Muestra una tabla con todos los dispositivos registrados en tu cuenta Weenat.

```
[weenat_devices]
```

---

### `[weenat_measurements]`

Muestra las mediciones de un dispositivo concreto.

| Atributo    | Requerido | Descripción                                              | Ejemplo          |
|-------------|-----------|----------------------------------------------------------|------------------|
| `device_id` | ✅        | ID numérico del dispositivo (ver columna ID en la tabla) | `47025`          |
| `metrics`   | ❌        | Métricas separadas por coma. Si se omite, todas.         | `T,U,RR`         |
| `days`      | ❌        | Días hacia atrás a consultar (por defecto `1`).          | `2`              |
| `step`      | ❌        | Resolución temporal en minutos (por defecto `60`).       | `30`             |

**Ejemplos:**

```
[weenat_measurements device_id="47025"]
[weenat_measurements device_id="47025" metrics="T,U" days="3"]
[weenat_measurements device_id="47032" metrics="FF,FXY" days="1" step="30"]
```

---

## Diagnóstico de "Sin datos disponibles"

Si aparece el mensaje **"Sin datos disponibles"** comprueba:

1. **Clave de API**: verifica que `WEENAT_API_KEY` está definida correctamente en `wp-config.php`.
2. **Período**: el dispositivo puede no tener datos para el rango de fechas solicitado. Prueba con `days="7"`.
3. **Métricas**: confirma que las métricas indicadas existen en `available_metrics` del dispositivo.
4. **Log de errores de PHP/WordPress**: activa `WP_DEBUG` y `WP_DEBUG_LOG` en `wp-config.php` para ver mensajes de la API.
5. **Respuesta real de la API**: puedes añadir temporalmente `error_log( print_r( $data, true ) );` justo después de `$data = weenat_api_get(...)` para volcar la respuesta al log.

---

## Estructura de autenticación

El header enviado en cada petición es exactamente:

```
Authorization: Weenat-Api-Key <TU_CLAVE_AQUI>
```

Esto cumple con las instrucciones de autenticación de la API Weenat.