import sys
import fitz

files = [
    "docs/6. Marketing.pdf",
    "docs/6. Politica_de_Precios.pdf",
    "docs/6.2 Estratexias de captación e fidelización de clientes.pdf"
]

with open("docs_extracted_new.txt", "w", encoding="utf-8") as out:
    for f in files:
        out.write(f"\n\n--- START OF {f} ---\n")
        try:
            doc = fitz.open(f)
            for page in doc:
                out.write(page.get_text())
            out.write(f"\n--- END OF {f} ---\n")
        except Exception as e:
            out.write(f"Error extracting {f}: {str(e)}\n")

print("Extraction completed successfully into docs_extracted_new.txt")
