"""課綱解析正確率量測（``make eval`` 的進入點）。

對應 ``FR-SYL-02`` 的驗收條件：以不少於 20 份真實課綱組成的測試集量測，
週次日期與課程事件日期的抽取正確率各須 ≥ 80%。

這是 bdd-guide 的 **L3 層**。L3 不在提交流程中執行，因此不得單獨作為
「必要」需求的防線——``FR-SYL-02`` 的 schema 驗證與重試由 L1 的 PHPUnit
守住，這裡量的是「做得好不好」，不是「會不會壞」。

**尚未實作**：需要第三階段的抽取實作與 ``OI-03`` 的標註測試集才有意義。
其中人工標註須及早開始，工時通常比蒐集課綱本身更高。
"""

from __future__ import annotations

import sys
from pathlib import Path

CORPUS_DIR = Path(__file__).resolve().parent.parent / "corpus"
ANNOTATIONS_DIR = Path(__file__).resolve().parent.parent / "annotations"


def main() -> int:
    print("課綱解析正確率量測（FR-SYL-02）")
    print()
    print("尚未實作。需要先完成：")
    print("  1. 第三階段的抽取實作（SRS §8.4）")
    print("  2. OI-03 的測試集與人工標註")
    print()
    print(f"  測試課綱放這裡：{CORPUS_DIR}")
    print(f"  標註檔放這裡：  {ANNOTATIONS_DIR}")
    print()
    print("兩者皆已列入 .gitignore——課綱含授課教師姓名與聯絡方式，不得進版控。")
    return 0


if __name__ == "__main__":
    sys.exit(main())
