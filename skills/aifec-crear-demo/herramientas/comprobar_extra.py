#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
AIFEC — comprobaciones de un paquete de demostración que `demo:check` NO hace.

    python comprobar_extra.py <carpeta del paquete> [--min 24] [--max 60] [--palabras 60] [--max-mb 5]

`demo:check` mira el formato (códigos que existen, precios enteros, GTIN, imágenes nombradas). Esto mira lo que
hace que el catálogo sea creíble y el paquete publicable: nombres y portadas que no se repiten, descripciones con
cuerpo, SVG limpios y ligeros, ningún dato personal ni de pago reales, ningún enlace escrito a mano, contenidos y
menús mínimos. Solo biblioteca estándar. Termina con «EXTRA: n errores, m avisos» y código 1 si hay errores.
"""
import hashlib
import json
import os
import re
import sys

ERRORES = []
AVISOS = []


def err(m):
    ERRORES.append(m)
    print("ERROR: " + m)


def aviso(m):
    AVISOS.append(m)
    print("AVISO: " + m)


def leer(ruta):
    with open(ruta, "r", encoding="utf-8-sig") as f:
        return json.load(f)


def textos(valor):
    """Todas las cadenas de una estructura JSON."""
    if isinstance(valor, str):
        yield valor
    elif isinstance(valor, list):
        for v in valor:
            for t in textos(v):
                yield t
    elif isinstance(valor, dict):
        for v in valor.values():
            for t in textos(v):
                yield t


def palabras(html):
    return len(re.findall(r"[^\W\d_]+", re.sub(r"<[^>]+>", " ", html or ""), re.UNICODE))


def bloques(lista):
    for b in lista if isinstance(lista, list) else []:
        if isinstance(b, dict):
            yield b
            for h in bloques(b.get("children")):
                yield h


def main():
    args = sys.argv[1:]
    if not args:
        print("Uso: comprobar_extra.py <carpeta del paquete> [--min N] [--max N] [--palabras N] [--max-mb N]")
        return 2
    opc = {"--min": 24, "--max": 60, "--palabras": 60, "--max-mb": 5}
    ruta = None
    i = 0
    while i < len(args):
        if args[i] in opc and i + 1 < len(args):
            opc[args[i]] = int(args[i + 1])
            i += 2
        else:
            ruta = args[i]
            i += 1
    if not ruta or not os.path.isfile(os.path.join(ruta, "package.json")):
        print("No hay package.json en " + str(ruta))
        return 2

    def dato(nombre):
        f = os.path.join(ruta, nombre)
        return leer(f) if os.path.isfile(f) else []

    meta = leer(os.path.join(ruta, "package.json"))
    productos, paginas, entradas = dato("products.json"), dato("pages.json"), dato("posts.json")
    categorias, menus, opiniones = dato("categories.json"), dato("menus.json"), dato("reviews.json")
    media = os.path.join(ruta, "media")
    ficheros_media = sorted(os.listdir(media)) if os.path.isdir(media) else []

    # --- el paquete en general
    permitidos = set(["package.json", "preview.png", "media"] + ["%s.json" % n for n in (
        "categories", "brands", "attributes", "products", "pages", "posts", "menus", "carriers", "coupons", "reviews")])
    for nombre in sorted(os.listdir(ruta)):
        if nombre not in permitidos:
            err("«%s» no es un fichero de paquete (los guiones y las notas van fuera de la carpeta)" % nombre)
    peso = 0
    for base, _dirs, fs in os.walk(ruta):
        for f in fs:
            peso += os.path.getsize(os.path.join(base, f))
            if re.search(r"\.(pem|key|p12|pfx|crt|env|cer)$", f, re.I) or re.search(r"redsys|paypal|stripe|secret", f, re.I):
                err("fichero con pinta de credencial o de pago real: " + f)
    if peso > opc["--max-mb"] * 1048576:
        err("el paquete pesa %.2f MB (tope de esta comprobación: %d MB)" % (peso / 1048576.0, opc["--max-mb"]))

    # --- catálogo
    if not (opc["--min"] <= len(productos) <= opc["--max"]):
        err("hay %d productos y tienen que ser entre %d y %d" % (len(productos), opc["--min"], opc["--max"]))
    nombres, resumenes, gtins, huellas_portada = {}, {}, {}, {}
    usadas = set()
    for p in productos:
        sku = p.get("sku", "?")
        n = re.sub(r"\s+", " ", str(p.get("name", ""))).strip().casefold()
        if n in nombres:
            err("nombre repetido «%s» (%s y %s)" % (p.get("name"), nombres[n], sku))
        nombres[n] = sku
        r = re.sub(r"<[^>]+>", "", str(p.get("summary", ""))).strip().casefold()
        if not r:
            err("%s: falta el resumen (summary)" % sku)
        elif r in resumenes:
            err("%s: el resumen es igual que el de %s" % (sku, resumenes[r]))
        resumenes[r] = sku
        if palabras(p.get("description", "")) < opc["--palabras"]:
            err("%s: la descripción tiene %d palabras (mínimo %d)" % (sku, palabras(p.get("description", "")), opc["--palabras"]))
        if len(p.get("facts") or []) < 3:
            err("%s: faltan datos clave (facts: mínimo 3)" % sku)
        if len(p.get("faqs") or []) < 2:
            err("%s: faltan preguntas frecuentes (faqs: mínimo 2)" % sku)
        if not p.get("attributes"):
            err("%s: sin atributos del sector" % sku)
        if not p.get("gtin") and not p.get("variants"):
            aviso("%s: sin código de barras (gtin)" % sku)
        for g in [p.get("gtin")] + [v.get("gtin") for v in p.get("variants") or []]:
            if g:
                if g in gtins:
                    err("código de barras repetido %s (%s y %s)" % (g, gtins[g], sku))
                gtins[g] = sku
        for v in p.get("variants") or []:
            if not str(v.get("sku", "")).startswith(str(sku)):
                aviso("%s: la variante %s no empieza por la referencia del producto" % (sku, v.get("sku")))
        imgs = [x if isinstance(x, str) else x.get("file", "") for x in p.get("images") or []]
        for f in imgs:
            usadas.add(f)
        for v in p.get("variants") or []:
            if v.get("image"):
                usadas.add(v["image"])
        if imgs and os.path.isfile(os.path.join(media, imgs[0])):
            h = hashlib.sha1(open(os.path.join(media, imgs[0]), "rb").read()).hexdigest()
            if h in huellas_portada:
                err("%s: su portada es idéntica a la de %s (mismo contenido, otro nombre)" % (sku, huellas_portada[h]))
            huellas_portada[h] = sku
        if len(imgs) < 2:
            aviso("%s: solo una imagen (lo recomendable son 2 o 3)" % sku)
    if productos:
        if not any((p.get("stock") == 0 and not p.get("variants")) for p in productos):
            aviso("ningún producto agotado: una demostración creíble tiene alguno sin existencias")
        if sum(1 for p in productos if p.get("sale_price")) < 3:
            aviso("menos de 3 productos en oferta")
        if sum(1 for p in productos if p.get("featured")) < 4:
            aviso("menos de 4 productos destacados (la portada los pide)")
        if sum(1 for p in productos if p.get("variants")) < 3:
            aviso("menos de 3 productos con variantes")
    for p in productos:
        if p.get("sale_price") and p.get("sale_price") >= p.get("price", 0):
            err("%s: la oferta no es menor que el precio" % p.get("sku"))
    cats_usadas = set(c for p in productos for c in p.get("categories") or [])
    for c in categorias:
        if c.get("code") not in cats_usadas and not any(x.get("parent") == c.get("code") for x in categorias):
            aviso("la categoría «%s» no tiene ningún producto" % c.get("code"))
        if not c.get("description"):
            aviso("la categoría «%s» no tiene descripción" % c.get("code"))

    # --- imágenes
    for f in ficheros_media:
        pf = os.path.join(media, f)
        tam = os.path.getsize(pf)
        if f.lower().endswith(".svg"):
            s = open(pf, encoding="utf-8").read()
            if "viewBox" not in s:
                err("media/%s: el SVG no tiene viewBox" % f)
            if re.search(r"<script|foreignObject|<image|href\s*=|xlink|data:|javascript:|\son\w+\s*=", s, re.I):
                err("media/%s: el SVG lleva script, enlaces, imágenes incrustadas o manejadores" % f)
            if re.search(r"https?://(?!www\.w3\.org/)", s):
                err("media/%s: el SVG lleva una dirección externa" % f)
            if tam > 20480:
                err("media/%s: pesa %d bytes (tope 20 KB)" % (f, tam))
        elif tam > 307200:
            aviso("media/%s: pesa %d KB (conviene que no pase de 300 KB)" % (f, tam // 1024))
        if f not in usadas and not any(f in t for t in textos([paginas, entradas, categorias, dato("brands.json")])):
            aviso("media/%s: nadie la usa" % f)

    # --- contenidos
    if len(paginas) < 4:
        err("hay %d páginas y hacen falta al menos 4 (portada, quiénes somos, envíos y devoluciones, contacto)" % len(paginas))
    if not any(b.get("type") == "form" for pg in paginas for b in bloques(pg.get("blocks"))):
        err("ninguna página lleva el formulario de contacto (bloque «form»)")
    if len(entradas) < 2:
        err("hay %d entradas de blog y hacen falta al menos 2" % len(entradas))
    for e in entradas:
        if palabras(" ".join(textos(e.get("blocks")))) < 200:
            aviso("entrada «%s»: menos de 200 palabras" % e.get("code"))
        if not any(b.get("type") == "products" for b in bloques(e.get("blocks"))):
            aviso("entrada «%s»: sin bloque de productos dentro" % e.get("code"))
    codigos_menu = set(m.get("code") for m in menus)
    for m in ("main", "footer"):
        if m not in codigos_menu:
            err("falta el menú «%s»" % m)
    for m in menus:
        if m.get("code") == "main" and len(m.get("items") or []) < 3:
            err("el menú principal tiene menos de 3 elementos")
    todo_texto = " ".join(textos([meta, paginas, entradas])).casefold()
    if "demostraci" not in todo_texto and "ejemplo" not in todo_texto:
        aviso("ningún texto dice que es una tienda de demostración")

    # --- valoraciones, cupones, envíos
    if len(opiniones) < 8:
        aviso("hay %d valoraciones (lo recomendable son 8 o más)" % len(opiniones))
    if opiniones and all(o.get("rating") == 5 for o in opiniones):
        err("todas las valoraciones son de cinco estrellas: ninguna parece real")
    if not dato("coupons.json"):
        aviso("sin cupones de ejemplo")
    transportistas = dato("carriers.json")
    lista_t = transportistas.get("carriers", []) if isinstance(transportistas, dict) else []
    if not any(t.get("handler") == "pickup" for t in lista_t) or not any(t.get("handler", "rates") == "rates" for t in lista_t):
        aviso("conviene un transportista con tarifas y otro de recogida")

    # --- datos personales, de pago y enlaces a mano en TODO lo que se publica
    fuentes = {}
    for nombre in sorted(os.listdir(ruta)):
        if nombre.endswith(".json"):
            fuentes[nombre] = " ".join(textos(leer(os.path.join(ruta, nombre))))
    for f in ficheros_media:
        if f.lower().endswith(".svg"):
            fuentes["media/" + f] = open(os.path.join(media, f), encoding="utf-8").read()
    for nombre, t in fuentes.items():
        for m in re.finditer(r"[\w.+-]+@([\w-]+(?:\.[\w-]+)+)", t):
            d = m.group(1).lower()
            if not (d.endswith(".test") or d in ("example.com", "example.org", "example.net")):
                err("%s: correo que puede ser real (%s): usa @ejemplo.test o @cliente.test" % (nombre, m.group(0)))
        # Las coordenadas de un SVG parecen teléfonos, NIF o IP: en media/ solo se miran correos, direcciones y claves.
        en_svg = nombre.startswith("media/")
        for m in ([] if en_svg else re.finditer(r"(?<![\d.])(?:\+34[ .-]?)?([6-9](?:[ .-]?\d){8})(?![\d])", t)):
            digitos = re.sub(r"\D", "", m.group(1))
            if len(digitos) == 9:
                err("%s: lleva un teléfono (…%s): una demostración no enseña ninguno, tampoco «de relleno», que es el de alguien" % (nombre, digitos[-3:]))
        for m in re.finditer(r"https?://([^/\s\"'<>)]+)", t):
            host = m.group(1).lower().split(":")[0]
            if nombre.startswith("media/") and host == "www.w3.org":
                continue
            if not (host.endswith(".test") or host.endswith("example.com") or host.endswith("example.org")):
                err("%s: dirección externa (%s): una demostración no enlaza a nadie real" % (nombre, host))
        if not en_svg and re.search(r"(?<![\w])\d{8}[A-HJ-NP-TV-Z](?![\w])", t) and not re.search(r"12345678Z", t):
            err("%s: algo con forma de NIF real" % nombre)
        if not en_svg and re.search(r"\b[A-Z]{2}\d{2}(?:[ ]?\d{4}){4,6}\b", t):
            err("%s: algo con forma de IBAN" % nombre)
        if not en_svg and re.search(r"\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b", t):
            err("%s: una dirección IP" % nombre)
        if re.search(r"sk_live|pk_live|BEGIN (?:RSA )?PRIVATE KEY|client_secret|merchant_?key|api[_-]?key", t, re.I):
            err("%s: algo con forma de clave o de credencial" % nombre)
        if re.search(r"href\s*=\s*[\"']/|\]\(/|href\s*=\s*[\"']\.\./", t):
            err("%s: enlace escrito a mano con la ruta de la tienda: usa menús o bloques (category_code, product_skus)" % nombre)

    print("Productos: %d · imágenes: %d · peso: %.2f MB" % (len(productos), len(ficheros_media), peso / 1048576.0))
    print("EXTRA: %d errores, %d avisos" % (len(ERRORES), len(AVISOS)))
    return 1 if ERRORES else 0


if __name__ == "__main__":
    sys.exit(main())
