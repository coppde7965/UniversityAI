from fastapi import APIRouter, HTTPException, Request, Form
from fastapi.responses import HTMLResponse, JSONResponse

router = APIRouter()

def _get_syllabi(request: Request):
    return getattr(request.app.state, "syllabi", {})

def _sync_schedule(syllabus: dict):
    weeks = syllabus.get("weeks", [])
    if "schedule" not in syllabus or not syllabus["schedule"]:
        syllabus["schedule"] = weeks.copy()

@router.get("/progress/{syllabus_id}")
async def progress_api(request: Request, syllabus_id: str):
    syllabi = _get_syllabi(request)
    syllabus = syllabi.get(syllabus_id)

    if not syllabus:
        raise HTTPException(status_code=404, detail="Syllabus not found")

    _sync_schedule(syllabus)
    return {
        "syllabus_id": syllabus_id,
        "title": syllabus.get("title", ""),
        "schedule": syllabus.get("schedule", []),
        "weeks": syllabus.get("weeks", []),
        "files": syllabus.get("files", []),
    }

@router.get("/progress/{syllabus_id}/page", response_class=HTMLResponse)
async def progress_page(request: Request, syllabus_id: str):
    syllabi = _get_syllabi(request)
    syllabus = syllabi.get(syllabus_id)

    if not syllabus:
        return HTMLResponse("<h1>Syllabus not found</h1>", status_code=404)

    _sync_schedule(syllabus)
    schedule = syllabus.get("schedule", [])

    rows = ""
    for w in schedule:
        mat_ids = w.get("material_ids", [])
        mat_str = ", ".join(mat_ids) if mat_ids else "（無）"
        rows += f"""
        <tr draggable="true" data-week="{w.get('week_no', '')}" data-row-id="{w.get('week_no', '')}">
            <td class="drag-handle">☰</td>
            <td class="week-cell">{w.get('week_no', '')}</td>
            <td>{w.get('date', '')}</td>
            <td><input type="text" name="topic_{w.get('week_no', '')}" value="{w.get('topic', '')}" style="width:100%;"></td>
            <td><input type="text" name="note_{w.get('week_no', '')}" value="{w.get('note', '')}" style="width:100%;"></td>
            <td>{mat_str}</td>
        </tr>
        """

    return f"""
    <html>
        <head>
            <meta charset="utf-8">
            <title>Progress</title>
            <style>
                body {{ font-family: sans-serif; max-width: 1100px; margin: 0 auto; padding: 20px; }}
                table {{ border-collapse: collapse; width: 100%; }}
                th, td {{ border: 1px solid #ccc; padding: 8px; text-align: left; vertical-align: top; }}
                th {{ background: #f5f5f5; }}
                .drag-handle {{ cursor: move; user-select: none; width: 32px; text-align: center; font-weight: bold; }}
                tr.dragging {{ opacity: 0.5; background: #f0f8ff; }}
                tr.drag-over {{ outline: 2px dashed #4e73df; }}
                .small {{ color: #666; font-size: 0.9em; }}
            </style>
        </head>
        <body>
            <h1>{syllabus.get('title', '')} - Progress</h1>
            <p class="small">拖曳整列可排序，儲存後會同步更新週次。</p>

            <form method="post" action="/progress/{syllabus_id}/save">
                <input type="hidden" name="row_order" id="row_order" value="">
                <table id="progressTable">
                    <tr>
                        <th>拖曳</th>
                        <th>Week</th>
                        <th>Date</th>
                        <th>Topic</th>
                        <th>Note</th>
                        <th>Materials</th>
                    </tr>
                    {rows if rows else "<tr><td colspan='6'>No schedule</td></tr>"}
                </table>
                <br>
                <button type="submit">儲存排序與修改</button>
            </form>

            <script>
            let draggedRow = null;

            function refreshWeekNumbers() {{
                const rows = [...document.querySelectorAll('#progressTable tr[data-row-id]')];
                rows.forEach((row, idx) => {{
                    const week = idx + 1;
                    row.dataset.week = week;
                    row.dataset.rowId = week;
                    row.querySelector('.week-cell').textContent = week;
                }});
            }}

            function buildRowOrder() {{
                const ids = [...document.querySelectorAll('#progressTable tr[data-row-id]')].map(r => r.dataset.rowId);
                document.getElementById('row_order').value = ids.join(',');
            }}

            function getDragAfterElement(container, y) {{
                const draggableElements = [...container.querySelectorAll('tr[data-row-id]:not(.dragging)')];
                return draggableElements.reduce((closest, child) => {{
                    const box = child.getBoundingClientRect();
                    const offset = y - box.top - box.height / 2;
                    if (offset < 0 && offset > closest.offset) {{
                        return {{ offset: offset, element: child }};
                    }} else {{
                        return closest;
                    }}
                }}, {{ offset: Number.NEGATIVE_INFINITY }}).element;
            }}

            function bindDrag() {{
                const table = document.getElementById('progressTable');
                const rows = table.querySelectorAll('tr[data-row-id]');
                rows.forEach(row => {{
                    row.draggable = true;
                    row.addEventListener('dragstart', () => {{
                        draggedRow = row;
                        row.classList.add('dragging');
                    }});
                    row.addEventListener('dragend', () => {{
                        row.classList.remove('dragging');
                        document.querySelectorAll('tr.drag-over').forEach(r => r.classList.remove('drag-over'));
                        refreshWeekNumbers();
                        buildRowOrder();
                    }});
                    row.addEventListener('dragover', e => {{
                        e.preventDefault();
                        row.classList.add('drag-over');
                        const after = getDragAfterElement(table, e.clientY);
                        if (after == null) {{
                            table.tBodies[0].appendChild(draggedRow);
                        }} else {{
                            table.tBodies[0].insertBefore(draggedRow, after);
                        }}
                        buildRowOrder();
                    }});
                    row.addEventListener('dragleave', () => {{
                        row.classList.remove('drag-over');
                    }});
                }});
            }}

            document.addEventListener('DOMContentLoaded', () => {{
                bindDrag();
                buildRowOrder();
            }});
            </script>
        </body>
    </html>
    """

@router.post("/progress/{syllabus_id}/save")
async def save_progress(request: Request, syllabus_id: str, row_order: str = Form("")):
    syllabi = _get_syllabi(request)
    syllabus = syllabi.get(syllabus_id)
    if not syllabus:
        raise HTTPException(status_code=404, detail="Syllabus not found")

    form = await request.form()
    schedule = syllabus.get("schedule", [])
    if row_order:
        order_ids = [int(x) for x in row_order.split(",") if x.strip().isdigit()]
        id_map = {item["week_no"]: item for item in schedule}
        new_schedule = []
        for idx, old_week in enumerate(order_ids, start=1):
            if old_week in id_map:
                item = id_map[old_week]
                new_schedule.append({
                    "week_no": idx,
                    "date": item.get("date", ""),
                    "topic": form.get(f"topic_{old_week}", item.get("topic", f"Week {idx}")),
                    "note": form.get(f"note_{old_week}", item.get("note", "")),
                    "material_ids": item.get("material_ids", [])
                })
        syllabus["schedule"] = new_schedule
        syllabus["weeks"] = new_schedule.copy()
    else:
        for item in schedule:
            w = item["week_no"]
            if f"topic_{w}" in form:
                item["topic"] = form[f"topic_{w}"]
            if f"note_{w}" in form:
                item["note"] = form[f"note_{w}"]
    return JSONResponse({"status": "ok"})