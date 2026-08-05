from pathlib import Path
import json
from typing import Any

DATA_DIR = Path("data")
DATA_DIR.mkdir(exist_ok=True)

SYLLABI_FILE = DATA_DIR / "syllabi.json"
MATERIALS_FILE = DATA_DIR / "materials.json"
SEARCH_FILE = DATA_DIR / "search_index.json"
PROGRESS_FILE = DATA_DIR / "progress.json"


def _load_json(path: Path, default: Any):
    if not path.exists():
        return default
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return default


def _save_json(path: Path, data: Any):
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def load_syllabi():
    return _load_json(SYLLABI_FILE, [])


def save_syllabi(data):
    _save_json(SYLLABI_FILE, data)


def load_materials():
    return _load_json(MATERIALS_FILE, [])


def save_materials(data):
    _save_json(MATERIALS_FILE, data)


def load_search_index():
    return _load_json(SEARCH_FILE, [])


def save_search_index(data):
    _save_json(SEARCH_FILE, data)


def load_progress_tables():
    return _load_json(PROGRESS_FILE, [])


def save_progress_tables(data):
    _save_json(PROGRESS_FILE, data)