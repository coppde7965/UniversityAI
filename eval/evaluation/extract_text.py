"""把 ``build/`` 下的課綱 PDF 抽成純文字，供第三階段閘門的對照組使用。

閘門要比的是「PDF 直接送 API」與「先抽文字再送」兩條路。這支腳本負責後者
的前半段。

**為什麼抽文字這一步在 Python 而不是 PHP**：目前 PHP 端沒有 PDF 文字抽取的
實作，Moodle 核心也沒有帶（``pdflib.php`` 是 TCPDF，只能產生不能解析）。
若閘門的結論是走文字路線，那條路就還欠一個 PHP 實作——可能是 vendor 進
``smalot/pdfparser``，也可能是外部程序。**那筆成本是這條路的一部分**，
量測時必須一併列入考慮，不能因為評測腳本用 Python 抽得出來就當作免費。

反過來說，若結論是直接送 PDF，這筆成本就完全不存在。這是兩條路的差別中
最容易被忽略的一項，所以寫在這裡而不是註腳。
"""

from __future__ import annotations

import sys
from pathlib import Path

from evaluation.pdf_text import NoTextLayerError, extract_from_path

# 相對於本模組解析，不寫死容器路徑：同一份 eval/ 目錄在 eval 容器掛在
# /work，在 moodle 容器掛在 /opt/uai/eval。寫死其中一個，另一邊就會用一句
# 「找不到目錄」失敗，而那個訊息看不出是掛載點不同造成的。
BUILD_DIR = Path(__file__).resolve().parent.parent / "build"


def main() -> int:
    if not BUILD_DIR.is_dir():
        print(f"找不到 {BUILD_DIR}，請先執行 make fixtures。", file=sys.stderr)
        return 1

    pdfs = sorted(BUILD_DIR.glob("*.pdf"))
    if not pdfs:
        print(f"{BUILD_DIR} 裡沒有 PDF，請先執行 make fixtures。", file=sys.stderr)
        return 1

    failures = 0
    for pdf in pdfs:
        target = pdf.with_suffix(".txt")
        try:
            extracted = extract_from_path(pdf)
        except NoTextLayerError as exc:
            print(f"  {pdf.name:<22} 無文字層：{exc}", file=sys.stderr)
            failures += 1
            continue

        target.write_text(extracted.text, encoding="utf-8")
        print(f"  {pdf.name:<22} {extracted.page_count} 頁　{len(extracted.text):>5} 字元 → {target.name}")

    print()
    print(f"完成 {len(pdfs) - failures} / {len(pdfs)} 份。")

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
