#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
AIFEC — dibujos SVG propios para un paquete de demostración (solo biblioteca estándar).

Uso desde tu guion generador (el guion vive FUERA del paquete y del proyecto):

    import sys; sys.path.insert(0, r"<ruta de esta carpeta>")
    import ilustrar as il
    svg = il.producto("libreta", "azul", vista="principal", etiqueta=2)
    il.guardar("media/libreta-alba-principal.svg", svg)

Formas: libreta, anillas, boligrafo, lapiz, rotulador, carpeta, archivador, frasco, regla, cinta, notas,
sobres, goma, grapadora, caja. Vistas: «principal» (el objeto entero) y «detalle» (más cerca, otro fondo).
Paletas: una por nombre (ver PALETAS) o un color `#rrggbb`.

Reglas que cumple lo que sale de aquí (y que demo:check no mira): `viewBox`, sin texto, sin enlaces ni
guiones, sin azar (la misma llamada da siempre los mismos bytes) y menos de 20 KB por fichero.
Para que dos productos NO den la misma imagen, cambia la forma, la paleta o `etiqueta` (un número 0-9 que
mueve los detalles): el comprobador rechaza dos ficheros idénticos.
"""
import os

PALETAS = {
    "azul": "#2f5d8a", "negro": "#2b2b2b", "rojo": "#b8433a", "verde": "#4f7d5b", "crudo": "#c9b48a",
    "amarillo": "#d8a932", "rosa": "#d98aa0", "violeta": "#7a5ea6", "gris": "#7d8791", "naranja": "#d9783a",
    "turquesa": "#3d9a9a", "burdeos": "#7a2e3b", "marino": "#1f3a5f", "madera": "#b98a52",
}


def _rgb(h):
    h = PALETAS.get(h, h).lstrip("#")
    return int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)


def tono(color, f):
    """Aclara (f > 0, hasta 1) u oscurece (f < 0, hasta -1) un color."""
    r, g, b = _rgb(color)
    if f >= 0:
        r, g, b = [int(c + (255 - c) * f) for c in (r, g, b)]
    else:
        r, g, b = [int(c * (1 + f)) for c in (r, g, b)]
    return "#%02x%02x%02x" % (r, g, b)


def _rect(x, y, w, h, fill, rx=0, extra=""):
    return '<rect x="%g" y="%g" width="%g" height="%g" rx="%g" fill="%s"%s/>' % (x, y, w, h, rx, fill, extra)


def _sombra(cx, cy, rx):
    return '<ellipse cx="%g" cy="%g" rx="%g" ry="%g" fill="#000" opacity="0.12"/>' % (cx, cy, rx, rx * 0.12)


def _libreta(c, e):
    d, l = tono(c, -0.3), tono(c, 0.35)
    o = [_sombra(300, 468, 140), _rect(190, 150, 220, 310, c, 10), _rect(190, 150, 26, 310, d, 10),
         _rect(386, 150, 8, 310, l, 0, ' opacity="0.5"'), _rect(240 + e * 4, 210, 120 - e * 4, 74, "#f4efe4", 4)]
    for i in range(3):
        o.append(_rect(252 + e * 4, 226 + i * 16, 96 - e * 4 - i * 14, 5, tono(c, -0.1), 2))
    o.append(_rect(380, 150, 14, 310, tono(c, -0.45), 0, ' opacity="0.7"'))
    return "".join(o)


def _anillas(c, e):
    o = [_sombra(300, 470, 150), _rect(180, 140, 240, 320, c, 8), _rect(196, 160, 212, 280, "#f6f3ea", 4)]
    for i in range(7 + e % 3):
        y = 175 + i * (250 / (6 + e % 3))
        o.append('<circle cx="190" cy="%g" r="9" fill="%s"/>' % (y, tono(c, -0.5)))
        o.append('<rect x="176" y="%g" width="42" height="7" rx="3.5" fill="#a7adb3"/>' % (y - 3.5))
    for i in range(7):
        o.append(_rect(232, 200 + i * 30, 160 - (i % 3) * 18, 4, "#c9d3de", 2))
    return "".join(o)


def _boligrafo(c, e):
    d = tono(c, -0.3)
    return (_sombra(300, 480, 150) + '<g transform="rotate(-35 300 300)">' + _rect(120, 280, 300, 40, c, 18)
            + _rect(120, 280, 300, 12, tono(c, 0.35), 6, ' opacity="0.6"') + _rect(396, 276, 44, 48, "#d8dbe0", 8)
            + '<polygon points="440,276 520,300 440,324" fill="%s"/>' % d
            + '<polygon points="500,292 520,300 500,308" fill="#2b2b2b"/>' + _rect(150, 262 - e % 3, 150, 12, "#b9bec5", 5)
            + _rect(100, 280, 30, 40, d, 12) + "</g>")


def _lapiz(c, e):
    return (_sombra(300, 480, 150) + '<g transform="rotate(-35 300 300)">' + _rect(120, 280, 280, 44, c, 4)
            + _rect(120, 280, 280, 11, tono(c, 0.3), 3, ' opacity="0.6"') + _rect(88, 280, 34, 44, "#e8a0a0", 8)
            + _rect(120, 270, 24, 64, "#c0c5cb", 3) + '<polygon points="400,280 480,302 400,324" fill="#e8cfa6"/>'
            + '<polygon points="456,294 480,302 456,310" fill="#2b2b2b"/>' + _rect(230, 296 + e % 3, 90, 5, "#fff", 2, ' opacity="0.7"') + "</g>")


def _rotulador(c, e):
    return (_sombra(300, 480, 140) + '<g transform="rotate(-30 300 300)">' + _rect(150, 276, 250, 48, "#f2f2f0", 14)
            + _rect(150, 276, 250, 48, c, 14, ' opacity="0.18"') + _rect(150, 276, 70, 48, c, 14)
            + _rect(400, 284, 40, 32, tono(c, -0.25), 6) + '<polygon points="440,288 486,300 440,312" fill="%s"/>' % c
            + _rect(236, 290, 120 - e * 3, 8, tono(c, -0.1), 4) + "</g>")


def _carpeta(c, e):
    d = tono(c, -0.25)
    return (_sombra(300, 450, 170) + '<polygon points="130,170 250,170 280,200 470,200 470,430 130,430" fill="%s"/>' % d
            + _rect(130, 215, 340, 215, c, 8) + _rect(130, 215, 340, 16, tono(c, 0.25), 8, ' opacity="0.6"')
            + _rect(225 + e * 4, 300, 90, 46, "#f4efe4", 4) + _rect(240 + e * 4, 316, 60, 6, tono(c, -0.1), 3))


def _archivador(c, e):
    d = tono(c, -0.3)
    return (_sombra(300, 462, 150) + _rect(190, 130, 220, 330, c, 6) + _rect(190, 130, 44, 330, d, 6)
            + _rect(250, 170, 130, 46, "#f4efe4", 4) + _rect(262, 186 + e % 3, 80, 6, d, 3)
            + '<ellipse cx="300" cy="360" rx="26" ry="26" fill="%s"/><ellipse cx="300" cy="360" rx="14" ry="14" fill="#f4efe4"/>' % d
            + _rect(250, 420, 130, 10, d, 3))


def _frasco(c, e):
    return (_sombra(300, 470, 110) + _rect(230, 250, 140, 210, "#dfe7ee", 20, ' opacity="0.9"') + _rect(238, 330, 124, 124, c, 14, ' opacity="0.85"')
            + _rect(268, 210, 64, 48, "#dfe7ee", 6) + _rect(260, 150, 80, 66, tono(c, -0.45), 10)
            + _rect(252, 350, 96, 60, "#f4efe4", 4) + _rect(264, 368 + e % 3, 60, 6, tono(c, -0.2), 3))


def _regla(c, e):
    o = ['<g transform="rotate(-20 300 300)">', _rect(100, 270, 400, 60, "#c9ced4", 6, ' stroke="#9aa1a9" stroke-width="2"')]
    for i in range(0, 38):
        h = 22 if i % 5 == 0 else 12
        o.append(_rect(112 + i * 10.2, 270, 2, h, "#444", 0))
    o.append(_rect(112, 300, 380 - e * 6, 5, c, 2, ' opacity="0.8"'))
    return _sombra(300, 470, 170) + "".join(o) + "</g>"


def _cinta(c, e):
    return (_sombra(300, 452, 130) + '<circle cx="300" cy="300" r="140" fill="%s"/>' % c
            + '<circle cx="300" cy="300" r="140" fill="none" stroke="%s" stroke-width="10" stroke-dasharray="%d 16"/>' % (tono(c, 0.45), 12 + e % 5)
            + '<circle cx="300" cy="300" r="66" fill="#f6f3ea"/><circle cx="300" cy="300" r="52" fill="#d9d2c0"/>')


def _notas(c, e):
    o = [_sombra(300, 470, 150)]
    for i in range(4):
        o.append(_rect(170 + i * 12, 170 + i * 14 + e % 3, 220, 220, tono(c, 0.1 * i), 4, ' stroke="#0000001a" stroke-width="2"'))
    o.append(_rect(230, 262, 120, 5, tono(c, -0.35), 2) + _rect(230, 286, 90, 5, tono(c, -0.35), 2))
    return "".join(o)


def _sobres(c, e):
    return (_sombra(300, 450, 170) + _rect(130, 190, 340, 230, c, 8)
            + '<polygon points="130,190 300,330 470,190" fill="%s"/>' % tono(c, 0.25)
            + '<polygon points="130,420 262,300 300,330 338,300 470,420" fill="%s"/>' % tono(c, -0.12)
            + _rect(380, 210 + e % 3, 60, 34, "#f4efe4", 3, ' opacity="0.9"'))


def _goma(c, e):
    return (_sombra(300, 430, 130) + '<g transform="rotate(-12 300 300)">' + _rect(160, 250, 280, 120, "#f6f3ea", 14, ' stroke="#d9d2c0" stroke-width="3"')
            + _rect(262, 240, 130, 140, c, 8) + _rect(278, 296 + e % 3, 98, 8, tono(c, 0.5), 4) + "</g>")


def _grapadora(c, e):
    d = tono(c, -0.3)
    return (_sombra(300, 440, 170) + _rect(120, 360, 360, 50, "#3b3b3b", 10)
            + '<polygon points="130,360 470,330 470,290 150,300" fill="%s"/>' % c
            + _rect(140, 296, 330, 14, tono(c, 0.35), 6, ' opacity="0.7"') + _rect(440, 300, 40, 70, d, 6)
            + _rect(170 + e * 4, 392, 120, 8, "#8a8f96", 4))


def _caja(c, e):
    return (_sombra(300, 450, 170) + _rect(150, 240, 300, 190, c, 8) + _rect(136, 200, 328, 56, tono(c, 0.2), 8)
            + _rect(230 + e * 3, 300, 140 - e * 3, 70, "#f4efe4", 4) + _rect(246 + e * 3, 322, 100 - e * 3, 8, tono(c, -0.3), 4)
            + _rect(246 + e * 3, 342, 70, 6, tono(c, -0.1), 3))


FORMAS = {"libreta": _libreta, "anillas": _anillas, "boligrafo": _boligrafo, "lapiz": _lapiz, "rotulador": _rotulador,
          "carpeta": _carpeta, "archivador": _archivador, "frasco": _frasco, "regla": _regla, "cinta": _cinta, "notas": _notas,
          "sobres": _sobres, "goma": _goma, "grapadora": _grapadora, "caja": _caja}


def _lienzo(w, h, cuerpo, fondo, caja=None):
    vb = caja or "0 0 %d %d" % (w, h)
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s" width="%d" height="%d">%s%s</svg>\n'
            % (vb, w, h, '<rect x="-2000" y="-2000" width="5000" height="5000" fill="%s"/>' % fondo, cuerpo))


def producto(forma, paleta, vista="principal", etiqueta=0, fondo="#f3efe7"):
    """Un dibujo cuadrado 600 × 600 de una forma. `vista='detalle'` acerca el objeto y cambia el fondo."""
    c = PALETAS.get(paleta, paleta)
    cuerpo = FORMAS[forma](c, int(etiqueta) % 10)
    if vista == "detalle":
        cuerpo = '<g transform="translate(-120 -150) scale(1.4)">' + cuerpo + "</g>"
        fondo = tono(fondo, -0.06)
    return _lienzo(600, 600, cuerpo, fondo)


def escena(formas, ancho=1600, alto=600, fondo="#efe9dc"):
    """Una escena panorámica (portada, «quiénes somos», entradas): [(forma, paleta, x, y, escala, etiqueta), …]."""
    cuerpo = ""
    for forma, paleta, x, y, s, e in formas:
        cuerpo += '<g transform="translate(%g %g) scale(%g)">%s</g>' % (x, y, s, FORMAS[forma](PALETAS.get(paleta, paleta), int(e) % 10))
    return _lienzo(ancho, alto, cuerpo, fondo)


def guardar(ruta, svg):
    carpeta = os.path.dirname(os.path.abspath(ruta))
    if not os.path.isdir(carpeta):
        os.makedirs(carpeta)
    with open(ruta, "w", encoding="utf-8", newline="\n") as f:
        f.write(svg)
    return len(svg.encode("utf-8"))


if __name__ == "__main__":
    # Prueba rápida: un dibujo de cada forma en la carpeta que se indique (o «salida_ilustrar»).
    import sys
    destino = sys.argv[1] if len(sys.argv) > 1 else "salida_ilustrar"
    for nombre in sorted(FORMAS):
        n = guardar(os.path.join(destino, nombre + ".svg"), producto(nombre, "azul", etiqueta=3))
        print("%-11s %5d bytes" % (nombre, n))
