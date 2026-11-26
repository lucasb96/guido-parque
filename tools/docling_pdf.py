"""Lê um PDF com o Docling e imprime o texto em Markdown (tabelas como tabelas).

Uso: python tools/docling_pdf.py arquivo.pdf [--ocr-pagina-inteira]

  --ocr-pagina-inteira  lê cada página como imagem (OCR), ignorando o texto do PDF.
                        Usado em PDFs escaneados e em PDFs com texto duplicado/sobreposto
                        (ex.: apresentações exportadas com duas camadas de texto).

Chamado por app/Extractor.php no robô (GitHub Actions). OCR em português pelo Tesseract.
"""
import re
import sys

from docling.datamodel.base_models import InputFormat
from docling.datamodel.pipeline_options import OcrMode, PdfPipelineOptions, TesseractCliOcrOptions
from docling.document_converter import DocumentConverter, PdfFormatOption


def main() -> int:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if len(args) != 1:
        print(__doc__, file=sys.stderr)
        return 2
    pagina_inteira = "--ocr-pagina-inteira" in sys.argv

    ocr = TesseractCliOcrOptions(lang=["por"])
    if pagina_inteira:
        ocr.mode = OcrMode.FULL_PAGE

    opts = PdfPipelineOptions()
    opts.do_ocr = True  # só age em áreas de imagem, a não ser com --ocr-pagina-inteira
    opts.do_table_structure = True
    opts.table_structure_options.do_cell_matching = not pagina_inteira
    opts.ocr_options = ocr

    conv = DocumentConverter(format_options={InputFormat.PDF: PdfFormatOption(pipeline_options=opts)})
    doc = conv.convert(args[0]).document
    md = doc.export_to_markdown()
    md = re.sub(r"<!--\s*image\s*-->", "", md)  # marcadores de imagem não interessam à busca
    sys.stdout.write(md)
    return 0


if __name__ == "__main__":
    sys.exit(main())
