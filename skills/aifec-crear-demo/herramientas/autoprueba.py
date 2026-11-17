#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
AIFEC — autoprueba de los comprobadores del skill.

    python autoprueba.py <paquete que pasa los dos comprobadores> [--php <ruta de php>]

Parte de un paquete válido (por ejemplo demos/moda), le hace una copia, le rompe una cosa cada vez y exige que el
comprobador que le toca (`demo_check.php` o `comprobar_extra.py`) termine con código 1. Si una rotura se cuela, el
comprobador está flojo y NO hay que fiarse de su «0 problemas». Termina con «CAZADAS: n de N» y código 0 solo si
caza todas. Trabaja siempre sobre una copia en la carpeta temporal y la borra al acabar; el paquete original no se toca.
"""
import json
import os
import shutil
import subprocess
import sys
import tempfile

AQUI = os.path.dirname(os.path.abspath(__file__))
PHP = "php"


def correr(cmd):
    r = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, stdin=subprocess.DEVNULL,
                       universal_newlines=True, encoding="utf-8", errors="replace")
    return r.returncode, r.stdout


def cargar(d, f):
    with open(os.path.join(d, f), encoding="utf-8-sig") as fh:
        return json.load(fh)


def guardar(d, f, x):
    with open(os.path.join(d, f), "w", encoding="utf-8", newline="\n") as fh:
        json.dump(x, fh, ensure_ascii=False, indent=2)


def primer_svg(d):
    for n in sorted(os.listdir(os.path.join(d, "media"))):
        if n.lower().endswith(".svg"):
            return os.path.join(d, "media", n)
    raise RuntimeError("el paquete no tiene ningún SVG para romper")


def anadir_al_svg(d, trozo):
    ruta = primer_svg(d)
    with open(ruta, encoding="utf-8") as fh:
        s = fh.read()
    with open(ruta, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(s.replace("</svg>", trozo + "</svg>", 1))


# --- roturas: cada una recibe la carpeta del paquete copiado
def precio(d):
    x = cargar(d, "products.json"); x[0]["price"] = 19.9; guardar(d, "products.json", x)


def portada_repetida(d):
    x = cargar(d, "products.json"); x[1]["images"][0] = x[0]["images"][0]; guardar(d, "products.json", x)


def gtin_falso(d):
    x = cargar(d, "products.json")
    for p in x:
        if p.get("gtin"):
            p["gtin"] = "8412345678905"
            break
        if p.get("variants"):
            p["variants"][0]["gtin"] = "8412345678905"
            break
    guardar(d, "products.json", x)


def categoria(d):
    x = cargar(d, "products.json"); x[2]["categories"] = ["no-existe"]; guardar(d, "products.json", x)


def sin_portada(d):
    x = cargar(d, "products.json"); x[0]["images"] = []; guardar(d, "products.json", x)


def menu_roto(d):
    x = cargar(d, "menus.json")
    for it in x[0]["items"]:
        if it.get("ref"):
            it["ref"] = "no-existe"
            break
    guardar(d, "menus.json", x)


def dos_portadas(d):
    x = cargar(d, "pages.json")
    for p in x:
        p["home"] = True
    guardar(d, "pages.json", x)


def pago_real(d):
    p = cargar(d, "package.json"); p.setdefault("modules", []).append("payments-redsys"); guardar(d, "package.json", p)


def ajuste_prohibido(d):
    p = cargar(d, "package.json"); p.setdefault("settings", {})["mail.host"] = "smtp.ejemplo.test"; guardar(d, "package.json", p)


def nombre_repetido(d):
    x = cargar(d, "products.json"); x[3]["name"] = x[2]["name"]; guardar(d, "products.json", x)


def descripcion_corta(d):
    x = cargar(d, "products.json"); x[4]["description"] = "<p>Un producto.</p>"; guardar(d, "products.json", x)


def correo_real(d):
    p = cargar(d, "package.json"); p["description"] += " Escribe a ana@gmail.com."; guardar(d, "package.json", p)


def telefono_real(d):
    p = cargar(d, "package.json"); p.setdefault("store", {})["phone"] = "999443322"; guardar(d, "package.json", p)


def enlace_a_mano(d):
    x = cargar(d, "pages.json")
    x[0]["blocks"].append({"type": "paragraph", "attrs": {"html": "<a href=\"/categoria/x\">x</a>"}})
    guardar(d, "pages.json", x)


def direccion_externa(d):
    p = cargar(d, "package.json"); p["description"] += " Más en https://tiendareal.es/oferta."; guardar(d, "package.json", p)


def svg_pesado(d):
    anadir_al_svg(d, "<!--" + "x" * 21000 + "-->")


def svg_con_script(d):
    anadir_al_svg(d, "<script>alert(1)</script>")


def svg_externo(d):
    anadir_al_svg(d, '<a xlink:href="http://x.example.net/x"><rect/></a>')


def fichero_de_clave(d):
    with open(os.path.join(d, "media", "redsys-clave.key"), "w") as fh:
        fh.write("x")


def cinco_estrellas(d):
    x = cargar(d, "reviews.json")
    for r in x:
        r["rating"] = 5
    guardar(d, "reviews.json", x)


def sin_formulario(d):
    x = cargar(d, "pages.json")
    for pg in x:
        pg["blocks"] = [b for b in pg["blocks"] if b.get("type") != "form"]
    guardar(d, "pages.json", x)


def imagen_identica(d):
    x = cargar(d, "products.json")
    a = x[0]["images"][0]; a = a if isinstance(a, str) else a["file"]
    b = x[5]["images"][0]; b = b if isinstance(b, str) else b["file"]
    shutil.copy(os.path.join(d, "media", a), os.path.join(d, "media", b))


def fichero_suelto(d):
    with open(os.path.join(d, "notas.md"), "w") as fh:
        fh.write("x")


ROTURAS = [
    ("precio con decimales", "php", precio), ("portada repetida", "php", portada_repetida), ("código de barras falso", "php", gtin_falso),
    ("categoría que no existe", "php", categoria), ("producto sin imagen", "php", sin_portada), ("menú que apunta a nada", "php", menu_roto),
    ("dos páginas de portada", "php", dos_portadas), ("módulo de pago real", "php", pago_real), ("ajuste prohibido", "php", ajuste_prohibido),
    ("nombre de producto repetido", "py", nombre_repetido), ("descripción corta", "py", descripcion_corta), ("correo que puede ser real", "py", correo_real),
    ("teléfono que puede ser real", "py", telefono_real), ("enlace escrito a mano", "py", enlace_a_mano), ("dirección externa", "py", direccion_externa),
    ("SVG de más de 20 KB", "py", svg_pesado), ("SVG con script", "py", svg_con_script), ("SVG con dirección externa", "py", svg_externo),
    ("fichero de clave en el paquete", "py", fichero_de_clave), ("todas las valoraciones de cinco estrellas", "py", cinco_estrellas),
    ("sin formulario de contacto", "py", sin_formulario), ("imagen idéntica a otra", "py", imagen_identica), ("fichero suelto en el paquete", "py", fichero_suelto),
]


def comprobar(quien, d):
    if quien == "php":
        return correr([PHP, os.path.join(AQUI, "demo_check.php"), d])
    return correr([sys.executable, os.path.join(AQUI, "comprobar_extra.py"), d])


def main():
    global PHP
    args = sys.argv[1:]
    if "--php" in args:
        i = args.index("--php")
        PHP = args[i + 1]
        del args[i:i + 2]
    if not args or not os.path.isfile(os.path.join(args[0], "package.json")):
        print("Uso: autoprueba.py <paquete válido> [--php <ruta de php>]")
        return 2
    origen = os.path.abspath(args[0])
    tmp = tempfile.mkdtemp(prefix="aifec-autoprueba-")
    try:
        d = os.path.join(tmp, os.path.basename(origen))
        for quien in ("php", "py"):
            rc, out = comprobar(quien, origen)
            if rc != 0:
                print("El paquete de partida NO pasa el comprobador «%s»; arréglalo antes de la autoprueba:" % quien)
                print(out[-800:])
                return 2
        caza = 0
        for nombre, quien, rotura in ROTURAS:
            if os.path.isdir(d):
                shutil.rmtree(d)
            shutil.copytree(origen, d)
            try:
                rotura(d)
            except (KeyError, IndexError, RuntimeError) as e:
                print("%-42s NO SE PUDO APLICAR (%s): el paquete no tiene esa parte" % (nombre, e))
                continue
            rc, _out = comprobar(quien, d)
            if rc == 1:
                caza += 1
            print("%-42s %-3s %s" % (nombre, quien, "CAZADA" if rc == 1 else "NO CAZADA (código %d)" % rc))
        print("CAZADAS: %d de %d" % (caza, len(ROTURAS)))
        return 0 if caza == len(ROTURAS) else 1
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
