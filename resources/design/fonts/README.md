# Las caras de la casa, embarcadas

Space Grotesk y Space Mono, en `woff2`, servidas por esta casa y no por un CDN — decisión de Rod
(greenhouse `decisions/0243`): un panel self-hosted no debería salir a la red para verse como es, y
un despliegue sin internet tampoco.

| archivo | qué es |
|---|---|
| `space-grotesk-latin.woff2`, `…-latin-ext.woff2` | **variable, 300–700**: un archivo cubre regular, medium, semibold y bold |
| `space-mono-400-*.woff2`, `space-mono-700-*.woff2` | estática, sólo los dos pesos que los tokens nombran |

**Origen**: los subsets `latin` y `latin-ext` que publica Google Fonts (`fonts.gstatic.com`), v22 de
Space Grotesk y v17 de Space Mono. Los `unicode-range` de `../milpa-fonts.css` son los de ese origen,
copiados: uno escrito a ojo hace que el navegador baje el subset equivocado, o ninguno.

**No se embarca `vietnamese`**: la casa escribe en inglés y español mexicano, y `latin` + `latin-ext`
los cubren. Añadirlo es copiar dos archivos más y una línea por cara.

## Licencia

**SIL Open Font License 1.1** — `OFL.txt`. Ambas familias son OFL: se pueden embarcar, servir y
redistribuir con el software, y la licencia viaja con ellas. Espejo de cómo esta casa ya embarca
Alpine (MIT, `resources/vendor/README.md`): lo vendorizado se nombra, con su licencia al lado.

## Actualizar

Bajar los `woff2` del origen otra vez y **volver a copiar los `unicode-range`** de la respuesta de
Google: cambian con la versión de la fuente, y una hoja con rangos viejos apuntando a archivos nuevos
es la forma silenciosa de que un carácter deje de renderizar.
