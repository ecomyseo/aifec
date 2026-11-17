# GM AI notice (`gm-aviso-ia`)

Plugin del sistema AIFEC que lleva a la tienda lo que hacía `ecom_avisoia` en WordPress: avisar a los visitantes de que los textos se han redactado con ayuda de IA. Viene **desactivado**.

## Qué hace

- **Ficha de producto**: un recuadro bajo el resumen (`product.summary.after`), al final de la ficha (`product.bottom`) o en los dos sitios.
- **Páginas y entradas del CMS**: el mismo recuadro encima del pie (hueco `footer.before`).
- **Pie de todas las páginas**: una línea corta, opcional.
- **Página explicativa**: una página propia en `/textos-con-ia` (cambiable) que cuenta qué escribe la IA, qué revisa una persona y cómo avisar de un error. Se puede sustituir por una página del CMS (ajuste «Información»).
- **Con el módulo `ai`**: con el ajuste «Solo donde una persona aprobó texto de IA» el aviso solo sale en lo que tiene una marca en `ai_marks`; no se duplica ningún dato. Si el módulo `ai` ya pone su propia marca al final de la ficha, este plugin no la repite.

## Cómo se configura

Panel → Ajustes → «AI notice», dos pestañas: General (dónde y a quién) y Textos (título, texto, enlace). Los textos vacíos usan la redacción de serie, ya traducida.

Los ajustes son **por sitio**. Al guardarlos se vacía la caché de páginas.

## Contrato AIFEC

Ningún servicio externo, ningún dato personal, ninguna tabla propia, ninguna capacidad. Salida cacheable y sin recursos externos. `php aifec contract:check gm-aviso-ia`.

Pruebas: `php tests/run.php plugins/gm-aviso-ia`.
