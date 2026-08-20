from PIL import Image, ImageDraw
import math

S = 4  # supersampling
W = 256 * S

img = Image.new('RGBA', (W, W), (0, 0, 0, 0))
d = ImageDraw.Draw(img)

# --- fond : carré arrondi plein cadre avec dégradé vertical bleu ---
margin = 4 * S
radius = 56 * S
top, bottom = (31, 107, 196), (19, 74, 142)

grad = Image.new('RGBA', (W, W), (0, 0, 0, 0))
gd = ImageDraw.Draw(grad)
for y in range(W):
    t = y / W
    color = tuple(int(top[i] + (bottom[i] - top[i]) * t) for i in range(3)) + (255,)
    gd.line([(0, y), (W, y)], fill=color)

mask = Image.new('L', (W, W), 0)
md = ImageDraw.Draw(mask)
md.rounded_rectangle([margin, margin, W - margin, W - margin], radius=radius, fill=255)
img.paste(grad, (0, 0), mask)
d = ImageDraw.Draw(img)

# --- géométrie : point vert en bas-gauche, pin rouge en haut-droite ---
green = (52 * S, 200 * S)
pin_center = (162 * S, 92 * S)   # centre de la tête du pin

# --- route en pointillés (bézier quadratique) ---
ctrl = (150 * S, 195 * S)
pin_tip = (pin_center[0], pin_center[1] + 58 * S)


def bezier(t, p0, p1, p2):
    x = (1 - t) ** 2 * p0[0] + 2 * (1 - t) * t * p1[0] + t ** 2 * p2[0]
    y = (1 - t) ** 2 * p0[1] + 2 * (1 - t) * t * p1[1] + t ** 2 * p2[1]
    return x, y


for i in range(1, 9):
    t = i / 9
    x, y = bezier(t, green, ctrl, pin_tip)
    if math.hypot(x - pin_tip[0], y - pin_tip[1]) < 26 * S:
        continue
    r = 5 * S
    d.ellipse([x - r, y - r, x + r, y + r], fill=(255, 255, 255, 150))

# --- point vert (technicien) ---
gx, gy = green
d.ellipse([gx - 17 * S, gy - 17 * S, gx + 17 * S, gy + 17 * S], fill=(255, 255, 255, 255))
d.ellipse([gx - 12 * S, gy - 12 * S, gx + 12 * S, gy + 12 * S], fill=(47, 179, 68, 255))


# --- pin rouge (lieu du ticket) : tête ronde + pointe triangulaire ---
def draw_pin(center, head_r, tip_dy, color):
    cx, cy = center
    # angle de raccord du triangle sur le cercle
    a = math.radians(38)
    left = (cx - head_r * math.cos(a), cy + head_r * math.sin(a))
    right = (cx + head_r * math.cos(a), cy + head_r * math.sin(a))
    tip = (cx, cy + tip_dy)
    d.polygon([left, tip, right], fill=color)
    d.ellipse([cx - head_r, cy - head_r, cx + head_r, cy + head_r], fill=color)


draw_pin(pin_center, 42 * S, 62 * S, (255, 255, 255, 255))  # contour blanc
draw_pin(pin_center, 35 * S, 52 * S, (229, 72, 77, 255))    # corps rouge
cx, cy = pin_center
d.ellipse([cx - 14 * S, cy - 14 * S, cx + 14 * S, cy + 14 * S], fill=(255, 255, 255, 255))

img = img.resize((256, 256), Image.LANCZOS)
img.save('/Users/jeremie/Herd/fleetview/fleetview.png')
print('logo écrit :', img.size)
