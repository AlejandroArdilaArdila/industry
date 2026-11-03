# Retenciones (Fuente / IVA / ICA) — estado Laravel vs ERP

Este documento existe para dejar claro **qué le está enviando hoy Laravel (api-c) al POS de escritorio sobre cada retención, qué le falta enviar para que el cálculo sea consistente con el ERP, y para qué sirve cada campo**. Es la base de referencia antes de tocar el código — no se ha implementado ningún cambio todavía a partir de este documento.

Estado del análisis: **Retefuente e IVA resueltos** (implementados y probados en `api-python`). **ICA NO está resuelto todavía** — se confirmó con contabilidad y con normativa pública que la regla de "misma ciudad" del ERP está mal, y que falta un dato nuevo (tarifa de ICA por ciudad de sucursal) que no existe hoy en ningún lado. Ver sección 3 para el detalle y la propuesta de rediseño. Pendiente aparte: la falta de curación de los booleanos `aplica*` por cliente en las 3 retenciones.

## Modelo general: las 3 retenciones se evalúan en 3 filtros

Antes de entrar en cada retención por separado, así funcionan las 3 (Fuente, IVA, ICA), en el mismo orden:

1. **Filtro 1 — ¿Este cliente puede tener esta retención en absoluto?** El booleano por cliente (`aplicaRetefuente`/`aplicaReteica`/`aplicaReteiva`). Si está en `false`, la retención correspondiente ni se calcula — no importa nada de lo que venga después.
2. **Filtro 2 — ¿Hay una exclusión de tributación que aplique a esta venta?** `exclusionesRetencionVenta.{fuente,ica,iva}`. La tabla (`integros_exclusiones_grupo_cuenta`) soporta reglas tanto por clasificación tributaria de la **empresa** como del **tercero** (cliente) — hoy, para ventas, solo hay reglas activas de tipo empresa (las de tercero que existen solo aplican a compras). Si algún día se agrega una regla de tercero con `aplica_venta=1`, este campo dejaría de ser exacto y habría que resolverla por cliente. Ver detalle en la sección "Exclusiones" más abajo.
3. **Filtro 3 — Ya pasando los dos anteriores, ¿cuánto se retiene según las condiciones de la operación?** Cada retención tiene su propia lógica de tarifa/umbral (ver cada sección). La regla de "misma ciudad" de ICA vive aquí, como una condición más de esta capa, no como un filtro aparte.

**El filtro 2 (exclusiones) tiene más peso que el filtro 1**: si aplica una exclusión (hoy, siempre por clasificación de la empresa, ya que las de tercero activas solo son para compras), no hay booleano de cliente que la pueda revertir. Esto también coincide con el orden real del código: el filtro 2 se evalúa primero (decide qué funciones de retención entran a la lista de "por ejecutar" en `getMovimientosContablesProducto()`), y el filtro 1 se evalúa después, ya sobre esa lista reducida, en `calcularRetencionesFactura()`.

## 0. Exclusiones que aplican a las 3 retenciones (Filtro 2, transversal)

Antes de entrar en cada retención por separado: hay una regla de **exclusión por clasificación tributaria de la propia empresa** que puede desactivar ICA y Retefuente por completo en ventas, independientemente de todo lo demás.

- Viene de un experimento (`feature/exclusiones-retenciones` en `custom/integros`, commit `9f32f0b4`) que se revirtió el mismo día (15-sep-2026, commit `d8a54ac2`) porque la tabla nueva no existía sin reinicializar el módulo. **Hoy el código NO usa esa rama** — sigue vigente la lógica anterior, hardcodeada, en `custom/integros/lib/movimientosContables.lib.php:11-20`.
- Regla vigente hoy: **si la empresa (`MAIN_INFO_CLAS_TRIBUTARIA`) es clasificación 5 o 6 (Régimen Simple), se excluyen ICA y Retefuente en TODAS las ventas** (no aplica a IVA en ventas). Hay otra regla que excluye reteIVA por clasificación del *tercero* pero solo en compras — no relevante para el POS, que solo hace ventas.
- El dato que se necesita (`idClasificacionTributaria` de la empresa) **ya lo expone Laravel** vía `GET /pos/configuracion` (`ConfiguracionService.php`). Lo que falta es la **regla de negocio** en el POS: si `idClasificacionTributaria` ∈ {5,6} → no calcular ICA ni Retefuente en ninguna venta.
- En esta BD de prueba la empresa tiene clasificación `2` (Responsable IVA), así que la regla no se dispara aquí — pero el POS debe implementarla igual, porque otras instalaciones del sistema sí pueden estar en régimen simple.

## 1. Retención en la Fuente (retefuente) — Filtros 1 y 3

### Cómo lo calcula el ERP
- Función: `calcularRetencionesFuente()`, `custom/integros/lib/productos.lib.php:892-982`.
- Modo `INTEGROS_MODO_RETENCION` — fijado explícitamente en `llx_const` como **`general`** (2026-09-21; antes dependía de un default implícito del código). El modo `producto` existe pero no está en uso (0 de 7,212 productos con `base_retencion_venta`/`tarifa_retencion_venta` configurados, y el hook de Dolibarr oculta esos campos del formulario mientras el modo sea `general`).
- En modo `general`: tarifa y base salen de la **plantilla contable** según `clasificacion_tributaria` del cliente. **Corrección** (dato verificado 2026-09-22, el hallazgo anterior de "tipo 1/2 → 2.5%, tipo 3 → 3.5%" estaba mal): en la plantilla de ventas real hoy, **todos** los tipo_contribuyente (1, 2, 3, 7, 8) apuntan a la misma cuenta, **2.5% flat, base 10 UVT**. Existen cuentas al 3.5% en el catálogo (`13551508`, `13551509`) pero no están vinculadas a ningún tipo_contribuyente en la plantilla de venta actual. El endpoint que se expuso es 100% data-driven (no asume ninguna tarifa fija), así que si algún día cargan una plantilla con tarifas distintas por tipo_contribuyente, se reflejaría solo.
- El cálculo agrupa líneas **por cuenta contable** (no por factura completa, no aislado por línea): se suma `total_ht` de las líneas del mismo grupo, se compara contra el umbral, y si lo supera, cada línea se retiene con la tarifa de su grupo.
- Condiciones (las 3 deben cumplirse): `societe_extrafields.retefuente=1` en el cliente; tarifa resuelta vía `clasificacion_tributaria`; base acumulada del grupo ≥ `base_imponible (UVT) × UVT vigente`.

### Qué envía Laravel (estado final, implementado en `api-python`)
| Campo | Fuente | Endpoint | Para qué sirve realmente |
|---|---|---|---|
| `aplicaRetefuente` | `societe_extrafields.retefuente` | `POST /customers/clients` (`ClientesService::formatearCliente()`) | ¿Aplica retefuente a este cliente? |
| `tarifasRetefuente` | `integros_plantillas_contables_cuentas` + `cuentas_contables` (grupo 11, tipo_doc 2) | `GET /pos/configuracion` (`ConfiguracionService::tarifasRetefuente()`) | Tabla real tipo_contribuyente → {porcentaje, baseUvt}, data-driven. |
| `exclusionesRetencionVenta.fuente` | `integros_exclusiones_grupo_cuenta` | `GET /pos/configuracion` | Si la empresa es régimen simple (5/6), excluye Retefuente en ventas. |
| `clasificacion`/`clasificacion_tributaria` | `societe_extrafields.clasificacion_tributaria` | `POST /customers/clients` | Clave para cruzar contra `tarifasRetefuente`. |
| `uvt`, `anioUvt` | `llx_c_uvt` | `GET /pos/configuracion` | Ya estaba, correcto. |
| `baseUvt`/`retencionUvt` | `product_extrafields.base_retencion_venta`/`tarifa_retencion_venta` | `POST /products/searchProducto` | Solo modo `producto` — **sigue inerte hoy**, se deja por si algún día migran de modo. |

Lo que Laravel **recibe** del POS y solo persiste sin validar (`FacturacionService::validarFacturaPos()`, líneas 317-323 y 409-413): `retefuente`, `ventilation_retefuente`, `tarifa_retencion_aplicada`, `base_retencion_aplicada` por línea; `retefuente` de encabezado. Todo `$linea[...] ?? null`, sin recalcular ni validar — esto no cambió, sigue siendo un modelo de confianza total en el cliente de escritorio (ver punto 4 más abajo).

### Ya resuelto ✅ / lo que queda pendiente
1. ✅ Booleano del cliente expuesto.
2. ✅ Tabla de tarifas de modo `general` expuesta (data-driven, no asume nada fijo).
3. `baseUvt`/`retencionUvt` siguen inertes — documentado, no se tocan hasta que decidan si migran a modo `producto`.
4. (Opcional, mayor alcance, sigue pendiente) validar server-side el `retefuente` que manda el cliente contra `tarifasRetefuente`/`aplicaRetefuente`, en vez de solo persistirlo.

### Cliente/producto de prueba real
- Cliente rowid `10`, "COMERCIALIZADORA DIA SAS" — `retefuente=1`, `clasificacion_tributaria=2`.
- Producto rowid `3877`, "TEQUILA DON JULIO AÑEJO 700ML" — plantilla 15, tipo_contribuyente=2 → 2.5%, base 10 UVT.
- UVT vigente $52.374 → umbral $523.740. Con 2 unidades ($577.142,86) → retefuente esperada ≈ **$14.428,57**.

## 2. Retención de IVA (reteiva) — Filtros 1 y 3

### Cómo lo calcula el ERP
- Función: `calcularRetencionIva()`, `custom/integros/lib/productos.lib.php:521-598`.
- A diferencia de retefuente, se calcula **sobre la factura completa** (como ICA), no por línea: `retencionIva = (total_tva_factura * porcentaje) / 100`. El desglose por línea (`facturedet_extrafields.reteiva`/`ventilation_reteiva`) es un reparto proporcional al IVA de cada línea, no un cálculo independiente — y solo se guarda en líneas con `fk_product` (las de texto libre no reciben desglose).
- Tarifa: tabla `llx_retenciones_conf` (tipo=2=IVA). **Hoy solo existe una fila activa**: "Rete IVA tarifa 15%", `porcentaje=15`, `base_uvt=27`. El umbral guardado en BD (`base_imponible=$1.271.000`, de 2020) está obsoleto — el sistema lo recalcula en vivo como `27 UVT × UVT vigente`, hoy = **$1.414.098**.
- Condiciones: `societe_extrafields.reteiva=1` en el cliente (chequeado fuera de la función, en el orquestador `calcularRetencionesFactura()`); el cliente debe tener un `fk_reteiva` válido apuntando a ese concepto; al menos un producto de la factura debe tener su plantilla contable con el grupo 10 (IVA) configurado; `total_ht ≥ $1.414.098` (recalculado, no el valor guardado). Para notas crédito con factura origen, hereda el resultado de esa factura en vez de recalcular.

### Qué envía Laravel (estado final, implementado en `api-python`)
| Campo | Fuente | Endpoint | Para qué sirve realmente |
|---|---|---|---|
| `aplicaReteiva` | `societe_extrafields.reteiva` | `POST /customers/clients` | ¿Aplica reteiva a este cliente? |
| `fk_reteiva` | `societe_extrafields.fk_reteiva` | `POST /customers/clients` (agregado — antes solo estaba en `clientesCompletos`, que es de la app Integros One, no del POS) | Qué concepto le corresponde. |
| `conceptosReteiva` | `retenciones_conf` (tipo=2) | `GET /pos/configuracion` | Detalle del/los conceptos: porcentaje + umbral en pesos (recalculado en vivo contra el UVT vigente). Hoy solo hay 1 concepto activo (15%, 27 UVT = $1.414.098). |
| `exclusionesRetencionVenta.iva` | `integros_exclusiones_grupo_cuenta` | `GET /pos/configuracion` | Siempre `false` en ventas hoy (ninguna regla de IVA aplica a ventas), pero queda ahí por si cambia. |

Lo que Laravel recibe del POS: mismo patrón que retefuente — persiste `reteiva` de encabezado (`FacturacionService.php`) tal cual lo manda el cliente, sin validar.

### Ya resuelto ✅ / lo que queda pendiente
1. ✅ Booleano del cliente expuesto.
2. ✅ `fk_reteiva` ahora sí llega al endpoint real del POS (`POST /customers/clients`), no solo a `clientesCompletos`.
3. ✅ Detalle del concepto (`conceptosReteiva`) expuesto dentro de `getConfiguracion()`.
4. La exclusión transversal no afecta IVA en ventas — confirmado, no requiere acción.
5. (Opcional, mismo alcance que retefuente) validar server-side en vez de solo persistir lo que manda el cliente.

### Cliente de prueba real
Candidatos con `reteiva=1` y `fk_reteiva=3` (el único concepto activo): **ALMIN SAS (rowid 12)**, Bancolombia S.A. (rowid 42), SISTEMA ZETA SAS (rowid 190). Con cualquiera de ellos, una factura con `total_ht ≥ $1.414.098` debe retener 15% del IVA de esa factura.

## 3. Retención de ICA (reteica) — Filtros 1 y 3

### Cómo lo calcula el ERP
- Función: `calcularRetencionIca()`, `custom/integros/lib/productos.lib.php:602-678`.
- Se calcula **sobre la factura completa** (como IVA, no por línea como retefuente): `retencionIca = (total_ht * tarifa) / 1000` — **por mil, no por ciento** (`llx_c_actividad_economica_ica.tarifa` está en por-mil).
- **Corrección importante (verificada 2026-09-22)**: para ventas (`isFourn=false`, el único caso del POS), la tarifa sale de `getRetencionIcaEmpresa()`, que usa **`MAIN_INFO_APE` — el CIIU de la EMPRESA misma, fijo, no el del cliente**. `getRetencionIcaTercero()` (que sí usa el CIIU del *tercero*) solo se usa en compras (`isFourn=true`). Esto tiene sentido fiscal: el ICA grava la actividad de quien vende (nosotros), no la del comprador — por eso `agrupacion`/`ciuu` del cliente **no se necesitan para este cálculo**, aunque siguen expuestos en `POST /customers/clients` (ya existían antes de este trabajo, quizás para otro uso).
- **Condición de ciudad — corregida tras confirmar con contabilidad**: el código actual compara `MAIN_INFO_SOCIETE_TOWN_ID` (domicilio registrado de la empresa) contra `tercero->town` (domicilio registrado del cliente), y si no coinciden, `reteica = 0`. **Confirmado que esto está mal**: el criterio real de territorialidad del ICA es **dónde se entrega la mercancía o se presta el servicio** (municipio de la sucursal/almacén que despachó esa venta), no el domicilio registrado de la empresa ni del cliente. Ver "Hallazgo" más abajo.
- Si la venta se territorializa en la ciudad correcta: además debe superar `total_ht ≥ base_imponible` (base_imponible en UVT, recalculado en vivo).
- Depende también de `societe_extrafields.reteica=1` en el cliente (chequeado en el orquestador `calcularRetencionesFactura()`, no dentro de la función) — con la corrección de ciudad, este pasa a ser el filtro principal (ver hallazgo).

### Qué envía Laravel hoy
| Campo | Fuente | Endpoint | Para qué sirve realmente |
|---|---|---|---|
| `aplicaReteica` | `societe_extrafields.reteica` | `POST /customers/clients` | ¿Aplica reteica a este cliente? (casi todos en `1`, sin curar; "Consumidor final" es la única excepción real de negocio, en `NULL`/no-aplica) |
| `agrupacion`/`ciuu` (cliente) | `societe.ape` → `c_codigos_ciiu.agrupacion` | `POST /customers/clients` | **No se necesita para ICA en ventas** (ver corrección arriba) — se deja porque ya existía, puede servir para otra cosa. |
| `idCiudad` (cliente) | `c_ciudades_fe.rowid` vía `societe.town` | `POST /customers/clients` | **Tampoco es el dato correcto para la regla de ciudad** (ver hallazgo) — el domicilio del cliente no define dónde se territorializa la venta. |
| `idCiudad` (empresa) | `c_ciudades_fe.rowid` vía `MAIN_INFO_SOCIETE_TOWN_ID` | `GET /pos/configuracion` | Mismo problema: es un domicilio fijo de toda la empresa, no varía por sucursal. |
| `actividadesEconomicasIca` (agrupacion→tarifa/base) | `c_actividad_economica_ica` | `GET /pos/actividadesEconomicasIca` | Corregido en este trabajo: `base_imponible` ahora se recalcula contra el UVT vigente. Para ventas, cruzar por la agrupación de **`MAIN_INFO_APE`** (empresa), no por la del cliente. |
| `exclusionesRetencionVenta.ica` | `integros_exclusiones_grupo_cuenta` | `GET /pos/configuracion` | Si la empresa es régimen simple (5/6), excluye ICA en ventas. |

### Hallazgo confirmado con contabilidad: la regla de ciudad está mal, y falta un dato nuevo
Confirmado con el contador y verificado contra normativa pública (territorialidad del ICA): el ICA se causa en el municipio donde **se entrega la mercancía o se presta el servicio**, no en el domicilio registrado de la empresa ni del cliente. Y ser agente retenedor de ICA de un municipio específico depende de si ese municipio (ej. Medellín) reconoce a ESE cliente puntual como tal (por acuerdo municipal, no por ser "Gran Contribuyente" nacional de la DIAN) — algo que no se puede inferir de ninguna combinación de datos que el sistema tenga hoy.

**Conclusión práctica para el POS**:
1. **Eliminar la regla de "misma ciudad" por completo** — no replicarla, ya sabemos que está mal.
2. **`aplicaReteica` pasa a ser el único filtro real** de "¿a este cliente se le retiene ICA?" — curado a mano por contabilidad (verificando el certificado de agente retenedor del cliente), reemplazando lo que la regla de ciudad intentaba aproximar automáticamente y no podía.
3. **Falta un dato que no existe en ningún lado todavía**: la tarifa de ICA de nuestra propia actividad económica **por cada ciudad donde tengamos sucursal** (el ICA varía por municipio incluso para la misma actividad — hoy `llx_c_actividad_economica_ica` es una tabla plana sin dimensión de ciudad). Esto conecta directo con Sucursales: la ciudad a usar en cada venta sería la de la sucursal/almacén que despachó esa venta, no un domicilio fijo de toda la empresa.
4. Este punto 3 es un cambio de modelo de datos en el ERP (`custom/integros`), no algo que se resuelva solo en api-c — queda como propuesta de rediseño para el equipo.

**Bug corregido en este trabajo (aparte, sigue vigente)**: `aplicaRetefuente`/`aplicaReteica`/`aplicaReteiva` en Laravel originalmente usaban `?? 1` (NULL → "sí aplica"), pero el ERP usa `empty()` en el orquestador (`productos.lib.php:749`), donde `empty(NULL)` es `true` → "no aplica". Corregido a `(bool) $valor` en ambos endpoints (`formatearCliente()` y `clientesCompletos()`).

## Pendientes generales de este documento
- Proponer al equipo el rediseño de `llx_c_actividad_economica_ica` con dimensión de ciudad, y de dónde sale la ciudad de territorialización por venta (sucursal/almacén) — bloqueante para que ICA quede realmente bien calculado, más allá de lo que ya se expuso.
- Curar `aplicaRetefuente`/`aplicaReteica`/`aplicaReteiva` por cliente real (hoy prácticamente todos en default) — trabajo de contabilidad, no de código.
- Decidir si la regla de exclusión de la sección 0 se implementa ya en el POS o se deja para cuando el ERP decida si retoma `feature/exclusiones-retenciones` con la tabla dinámica.
- (Opcional, las 3 retenciones) validar server-side en api-c lo que manda el cliente, en vez de solo persistirlo.
