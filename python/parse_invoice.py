import pdfplumber
import re
import json
import sys


# -------------------------
# Utils
# -------------------------

def normalize_text(text):
    return text.replace('\xa0', ' ').strip()


# -------------------------
# Extraction texte
# -------------------------

def extract_text(pdf_path):
    text = ""

    with pdfplumber.open(pdf_path) as pdf:
        for page in pdf.pages:
            t = page.extract_text()
            if t:
                text += "\n" + t

    return normalize_text(text)


# -------------------------
# Merge multi-lines
# -------------------------

def merge_multilines(text):
    lines = [l.strip() for l in text.split('\n') if l.strip()]
    merged = []

    i = 0
    while i < len(lines):
        current = lines[i]

        if re.search(r'\d+[.,]\d{2}$', current):
            block = current

            for j in range(1, 3):
                if i + j < len(lines):
                    block += " " + lines[i + j]

            merged.append(block)
            i += 3
        else:
            merged.append(current)
            i += 1

    return merged

def detect_invoice_ref(text):
    patterns = [
        r'facture\s*(n[°o]|num[eé]ro)?\s*[:\-]?\s*([A-Z0-9\-\/]+)',
        r'invoice\s*(no|number)?\s*[:\-]?\s*([A-Z0-9\-\/]+)',
        r'\b([A-Z]{1,3}[-_]?\d{4,})\b'  # fallback type A-FR-2026-010136137
    ]

    text_lower = text.lower()

    for p in patterns:
        matches = re.findall(p, text_lower, re.I)
        for m in matches:
            if isinstance(m, tuple):
                ref = m[-1]
            else:
                ref = m

            if len(ref) >= 5:
                return ref.upper()

    return None

# -------------------------
# SIREN detection
# -------------------------

def detect_siren(text):
    lines = text.split("\n")

    # -------------------------
    # 1. PRIORITÉ FOOTER (bas du doc)
    # -------------------------
    footer = "\n".join(lines[-20:])  # dernières lignes

    # TVA FR → SIREN
    match = re.search(r'FR\d{2}(\d{9})', footer)
    if match:
        return match.group(1)

    # SIREN direct
    sirens = re.findall(r'\b\d{9}\b', footer)
    if sirens:
        return sirens[0]

    # SIRET → SIREN
    sirets = re.findall(r'\b\d{14}\b', footer)
    if sirets:
        return sirets[0][:9]

    # -------------------------
    # 2. FALLBACK global (ancien comportement)
    # -------------------------
    match = re.search(r'FR\d{2}(\d{9})', text)
    if match:
        return match.group(1)

    sirens = re.findall(r'\b\d{9}\b', text)
    if sirens:
        return sirens[0]

    sirets = re.findall(r'\b\d{14}\b', text)
    if sirets:
        return sirets[0][:9]

    return None


# -------------------------
# Date extraction
# -------------------------

def detect_date(text):
    patterns = [
        r'(\d{2}/\d{2}/\d{4})',
        r'(\d{2}\.\d{2}\.\d{4})',
        r'(\d{4}-\d{2}-\d{2})'
    ]

    for p in patterns:
        match = re.search(p, text)
        if match:
            return match.group(1)

    return None


# -------------------------
# Totaux
# -------------------------

def detect_totals(text):
    totals = {
        "total_ht": None,
        "total_tva": None,
        "total_ttc": None
    }

    ht = re.search(r'Total\s*HT.*?(\d+[.,]\d{2})', text, re.I)
    tva = re.search(r'TVA.*?(\d+[.,]\d{2})', text, re.I)
    ttc = re.search(r'Total\s*TTC.*?(\d+[.,]\d{2})', text, re.I)

    if ht:
        totals["total_ht"] = float(ht.group(1).replace(',', '.'))
    if tva:
        totals["total_tva"] = float(tva.group(1).replace(',', '.'))
    if ttc:
        totals["total_ttc"] = float(ttc.group(1).replace(',', '.'))

    return totals

def detect_price_type_from_headers(words):
    header_text = " ".join(w["text"] for w in words).lower()

    if "montant ht" in header_text or "pu ht" in header_text or "prix ht" in header_text:
        return "ht"

    if "montant ttc" in header_text or "total ttc" in header_text or "pu ttc" in header_text or "prix ttc" in header_text:
        return "ttc"

    return "ttc"

# -------------------------
# Extraction lignes (layout)
# -------------------------

def extract_lines_from_page(words, price_type):
    lines = []

    rows = {}
    for w in words:
        key = round(w['top'], 1)
        rows.setdefault(key, []).append(w)

    for y in rows:
        row_words = sorted(rows[y], key=lambda w: w['x0'])
        line = " ".join(w['text'] for w in row_words)
        line_lower = line.lower()

        # -------------------------
        # FILTRES CRITIQUES (anti-bruit)
        # -------------------------

        # ignore TVA entre parenthèses (ex: "(2.38 €)")
        if re.match(r'^\(?\d+[.,]\d{2}\s*€\)?$', line.strip()):
            continue

        # ignore lignes trop courtes (ex: "2.34 €")
        if len(line.split()) < 4:
            continue

        # ignore totaux globaux
        if "net à payer" in line_lower:
            continue

        # ignore lignes commençant par parenthèse
        if line.strip().startswith("("):
            continue

        # -------------------------
        # Filtres globaux
        # -------------------------
        if (
            "total" in line_lower
            or "s/total" in line_lower
            or "paiement" in line_lower
            or line_lower.startswith("tva ")
            or "taxe sur la valeur ajoutée" in line_lower
        ):
            continue

        # téléphone
        if re.search(r'\+?\d[\d\s().-]{8,}', line) and "vente" not in line_lower:
            continue

        # -------------------------
        # Prix
        # -------------------------
        prices = re.findall(r'\d+[.,]\d{2}(?!\s*%)', line)

        if not prices:
            continue

        # TVA par défaut
        tva_rate = 20

        tva_match = re.search(r'(\d{1,2}(?:[.,]\d+)?)\s*%', line)
        if tva_match:
            tva_rate = float(tva_match.group(1).replace(',', '.'))

        if len(prices) == 1:
            total = float(prices[0].replace(',', '.'))
            unit_price = total
            qty = 1
        else:
            total = float(prices[-1].replace(',', '.'))
            unit_price = float(prices[-2].replace(',', '.'))

            if unit_price > 0:
                qty = round(total / unit_price, 3)
            else:
                qty = 1

            if qty <= 0 or qty > 10000:
                qty = 1

        # calcul HT
        if price_type == "ttc":
            price_ht = unit_price / (1 + tva_rate / 100)
        else:
            price_ht = unit_price

        # -------------------------
        # Description
        # -------------------------
        desc_match = re.search(r'\d+\s+(.+?)\s+\d{1,2},\d{2}%', line)

        if desc_match:
            desc = desc_match.group(1)
        else:
            desc = re.sub(r'\d+[.,]\d{2}.*$', '', line).strip()

        tva_match = re.search(r'(\d{1,2}(?:[.,]\d+)?)\s*%', line)

        if tva_match:
            tva_rate = float(tva_match.group(1).replace(',', '.'))
        else:
            tva_rate = 20

        lines.append({
            "desc": desc.strip(),
            "qty": qty,
            "price": unit_price,
            "price_type": price_type,
            "price_ht": price_ht,
            "tva": tva_rate
        })

    return lines


def detect_lines_from_layout(pdf_path):
    lines = []

    with pdfplumber.open(pdf_path) as pdf:

        # -------------------------
        # Détection table + type prix (page 1)
        # -------------------------
        first_page = pdf.pages[0]
        words_page1 = first_page.extract_words()

        header_text = " ".join(w["text"] for w in words_page1).lower()

        table_detected = any(x in header_text for x in [
            "description", "quantité", "pu", "total"
        ])

        price_type = detect_price_type_from_headers(words_page1)

        # -------------------------
        # Choix pages à parser
        # -------------------------
        if table_detected:
            pages = pdf.pages   # multipage
        else:
            pages = [pdf.pages[0]]  # fallback safe

        # -------------------------
        # Extraction
        # -------------------------
        for page in pages:
            words = page.extract_words()
            lines += extract_lines_from_page(words, price_type)

    return lines


# -------------------------
# MAIN
# -------------------------

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "No file"}))
        return

    pdf_path = sys.argv[1]

    text = extract_text(pdf_path)

    siren = detect_siren(text)
    date = detect_date(text)
    ref = detect_invoice_ref(text)
    totals = detect_totals(text)

    lines = detect_lines_from_layout(pdf_path)

    # recalcul total depuis les lignes
    if lines:
        total_calc = sum(l["price_ht"] * l.get("qty", 1) for l in lines)
    else:
        total_calc = None

    data = {
        "version": 1,
        "siren": siren,
        "supplier_name": None,
        "date": date,
        "ref": ref,

        # 👉 totaux issus du parsing (incertains)
        "total_ht": totals["total_ht"],
        "total_tva": totals["total_tva"],
        "total_ttc": totals["total_ttc"],

        # 👉 totaux calculés (fiables)
        "total_calc": total_calc,

        "lines": lines
    }

    print(json.dumps(data, ensure_ascii=False))


if __name__ == "__main__":
    main()
