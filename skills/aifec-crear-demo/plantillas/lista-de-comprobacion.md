# Lista de comprobación de la entrega (fase 9)

Se marca cada casilla **con la salida del comando delante**, no de memoria. Una casilla sin medida no está marcada.
Todos los comandos, desde la raíz del proyecto. Si el paquete aún no está en `demos/<sector>/`, usa `demo_check.php` con su carpeta.

## Formato

- [ ] `php aifec demo:check <sector>` termina en `DEMO <sector>: 0 problems`.
- [ ] `python skills/aifec-crear-demo/herramientas/comprobar_extra.py demos/<sector>` termina en `EXTRA: 0 errores, 0 avisos`
      (un aviso que decidas dejar se explica en la entrega, con su motivo).
- [ ] La carpeta solo contiene `package.json`, los ficheros de datos listados en `files`, `media/` y, si lo hace el director, `preview.png`.
      Ni guiones, ni notas, ni copias: eso vive fuera del paquete.

## Catálogo

- [ ] Entre 24 y 60 productos (`Products:` de `demo:check`). Cada uno con nombre, resumen y portada que no se repiten con ningún otro.
- [ ] Descripciones de 60 palabras o más, 3 o más datos clave y 2 o más preguntas por producto.
- [ ] Al menos 3 ofertas, 4 destacados, 3 productos con variantes, un agotado y uno con pocas existencias.
- [ ] Precios en céntimos enteros con el IVA dentro, dentro del rango que escribiste en la fase 1.
- [ ] Todos los códigos de barras empiezan por `299` y son distintos (productos y variantes).

## Imágenes

- [ ] Cada producto tiene portada propia y de 2 a 3 vistas; también la portada de la tienda, «quiénes somos» y cada entrada del blog.
- [ ] Cada SVG con `viewBox`, menos de 20 KB, sin script, sin enlaces y sin imágenes incrustadas (`comprobar_extra.py` lo mide).
- [ ] Si hay fotos, cada una tiene su licencia y su origen anotados fuera del paquete. Ninguna es de una tienda real.
- [ ] El guion generador vuelve a dar los mismos bytes (se ejecuta dos veces y se compara). Vive fuera del paquete.

## Contenidos

- [ ] Una sola página `home: true`; páginas de quiénes somos, envíos y devoluciones, contacto (con bloque `form`) y aviso legal.
- [ ] Dos entradas de blog de 200 palabras o más, cada una con un bloque `products`.
- [ ] Menús `main` (3 elementos o más), `footer` y `legal`. Ningún enlace escrito a mano con la ruta de la tienda.
- [ ] Todo texto visible deja claro que es de ejemplo; nada sugiere una tienda, una marca o una persona reales.

## Datos de mentira

- [ ] Correos solo `@ejemplo.test`, `@cliente.test` o `example.*`; ningún teléfono; «Calle Mayor 1».
- [ ] Ningún NIF, IBAN, dirección IP, clave ni fichero de credenciales; ningún módulo `payments-*` salvo el simulado.
- [ ] Opiniones con nombres de pila inventados, no todas de cinco estrellas.

## Peso y ortografía

- [ ] El paquete pesa 5 MB o menos (`Size:` de `demo:check`).
- [ ] `python C:\dev-tools\ortografia\revisar_ortografia.py <carpeta del paquete>` → `TOTAL: 0`. Sus avisos sobre formas
      verbales casi nunca son faltas: se miran uno a uno y no se usa `--arreglar`.

## Si has podido abrirla (fase 8)

- [ ] `demo:create` terminó sin error y recorriste portada, categoría, ficha con variantes, buscador, carrito, pago simulado, blog y contacto.
- [ ] Ninguna imagen sale cortada, repetida o en blanco; ninguna página desborda en móvil.
- [ ] Hiciste una compra de prueba con la persona inventada (el paquete no trae pedidos ni clientes).

## Y por último

- [ ] Lo que falló en el camino está en `referencias/fallos-frecuentes.md`, con su causa y su arreglo.
- [ ] Tu informe dice qué has comprobado y qué no, y las órdenes que ha de lanzar el director.
