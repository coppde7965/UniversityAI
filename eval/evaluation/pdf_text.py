"""課綱檔案的文字抽取。

原專案的 ``app/routes/syllabus.py`` 有一個四行的 pypdf 呼叫，架構文件 §10.1
原本規劃把它搬過來。實際檢視後改為重寫——四行標準呼叫沒有搬移價值，有價值
的只是「pypdf 這個選擇可用」這件事。

重寫時補上了原版缺少的一件事：**無文字層的偵測**。``FR-SYL-01`` 明文要求
「上傳無文字層的 PDF 時，系統在解析前即偵測並告知原因」，原版沒有這個檢查，
掃描版 PDF 會安靜地抽出空字串，然後在後面的流程裡變成一個難以理解的失敗。
"""

from __future__ import annotations

import io
from dataclasses import dataclass
from pathlib import Path

from pypdf import PdfReader

# 低於這個字元數就視為沒有文字層。掃描版 PDF 通常仍會抽出少量頁首頁尾或
# OCR 殘留，所以門檻不設 0。實際值待第三階段以測試集校準。
MIN_TEXT_LENGTH = 200


class NoTextLayerError(ValueError):
    """PDF 沒有可抽取的文字層（多半是掃描影像）。

    第一版明確不支援掃描影像 PDF（SRS §4.1）。這個例外的訊息會直接呈現給
    老師，因此要說清楚發生什麼事與下一步怎麼做（``NFR-USA-03``）。
    """


@dataclass(frozen=True)
class ExtractedText:
    text: str
    page_count: int
    chars_per_page: tuple[int, ...]


def extract_from_pdf(data: bytes) -> ExtractedText:
    """自 PDF 位元組抽取文字。

    Raises:
        NoTextLayerError: 抽出的文字量低於門檻。
    """
    reader = PdfReader(io.BytesIO(data))
    pages = [(page.extract_text() or "") for page in reader.pages]
    text = "\n".join(pages)

    if len(text.strip()) < MIN_TEXT_LENGTH:
        raise NoTextLayerError(
            "這份 PDF 沒有可讀取的文字層，看起來是掃描影像。"
            "第一版僅支援含文字層的 PDF 與 DOCX，"
            "請改用原始檔案，或先以其他工具轉為可選取文字的 PDF。"
        )

    return ExtractedText(
        text=text,
        page_count=len(pages),
        chars_per_page=tuple(len(p) for p in pages),
    )


def extract_from_path(path: Path) -> ExtractedText:
    """自檔案路徑抽取。目前只處理 PDF；DOCX 於第三階段補上。"""
    suffix = path.suffix.lower()
    if suffix == ".pdf":
        return extract_from_pdf(path.read_bytes())
    raise NotImplementedError(f"尚未支援的格式：{suffix}")
