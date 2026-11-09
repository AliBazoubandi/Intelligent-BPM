#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Document Text Extraction for RabinFlow Process Discovery
Extracts text from .docx, .pdf, .txt, .md files.
Uses python-docx for Word files and pypdf for PDF files.
Normalizes Unicode (NFKD) for Persian text.
Outputs JSON with ensure_ascii=True to avoid Windows charmap issues.
"""

import sys
import os
import json
import io
import unicodedata

# Force UTF-8 on Windows
if sys.platform.startswith('win'):
    try:
        sys.stdout.reconfigure(encoding='utf-8', errors='replace')
        sys.stderr.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        try:
            sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
            sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')
        except Exception:
            pass

def extract_from_docx(file_path):
    import docx
    doc = docx.Document(file_path)
    paragraphs = []
    for p in doc.paragraphs:
        t = p.text.strip()
        if t:
            paragraphs.append(t)
    for table in doc.tables:
        for row in table.rows:
            cells = [c.text.strip() for c in row.cells if c.text.strip()]
            dedup = []
            for c in cells:
                if not dedup or dedup[-1] != c:
                    dedup.append(c)
            if dedup:
                paragraphs.append(" | ".join(dedup))
    return "\n\n".join(paragraphs).strip()

def extract_from_pdf(file_path):
    import pypdf
    reader = pypdf.PdfReader(file_path)
    pages_text = []
    for page in reader.pages:
        pt = page.extract_text()
        if pt:
            norm = unicodedata.normalize('NFKD', pt)
            pages_text.append(norm.strip())
    return "\n\n".join(pages_text).strip()

def extract_from_txt(file_path):
    for enc in ['utf-8-sig', 'utf-8', 'cp1256', 'latin-1']:
        try:
            with open(file_path, 'r', encoding=enc) as f:
                return f.read().strip()
        except UnicodeDecodeError:
            continue
    with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
        return f.read().strip()

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "No input file provided"}, ensure_ascii=True))
        sys.exit(1)

    file_path = sys.argv[1]
    if not os.path.exists(file_path):
        print(json.dumps({"success": False, "error": f"File not found: {file_path}"}, ensure_ascii=True))
        sys.exit(1)

    ext = os.path.splitext(file_path)[1].lower().lstrip('.')
    try:
        if ext == 'docx':
            text = extract_from_docx(file_path)
        elif ext == 'pdf':
            text = extract_from_pdf(file_path)
        elif ext in ['txt', 'md', 'text', 'rtf']:
            text = extract_from_txt(file_path)
        else:
            print(json.dumps({"success": False, "error": f"Unsupported format: {ext}"}, ensure_ascii=True))
            return

        if not text:
            print(json.dumps({
                "success": False,
                "error": "فایل انتخاب‌شده متنی ندارد یا محتوای آن قابل استخراج نبود (مانند PDF اسکن‌شده تصویری)."
            }, ensure_ascii=True))
        else:
            print(json.dumps({
                "success": True,
                "text": text,
                "format": ext,
                "length": len(text)
            }, ensure_ascii=True))

    except Exception as e:
        print(json.dumps({
            "success": False,
            "error": f"خطا در پردازش فایل {ext}: {str(e)}"
        }, ensure_ascii=True))

if __name__ == '__main__':
    main()

