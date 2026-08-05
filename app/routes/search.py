from fastapi import APIRouter, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse

router = APIRouter()

@router.get("/search", response_class=HTMLResponse)
async def search_page(request: Request, q: str = "", user_id: str = ""):
    searxng_search = "http://localhost:8080/search?q="
    result_link = f"{searxng_search}{q}" if q else ""

    return f"""
    <html>
        <head>
            <meta charset="utf-8">
            <title>Search</title>
            <style>
                body {{ font-family: sans-serif; max-width: 1100px; margin: 0 auto; padding: 20px; }}
                input {{ width: 100%; padding: 8px; margin: 8px 0; }}
                .btn {{ display: inline-block; padding: 8px 12px; background: #0066cc; color: #fff; text-decoration: none; border-radius: 4px; }}
            </style>
        </head>
        <body>
            <h1>SearXNG 搜尋</h1>
            <form action="/search" method="get">
                <input type="hidden" name="user_id" value="{user_id}">
                <input type="text" name="q" value="{q}" placeholder="輸入關鍵字">
                <button type="submit">搜尋</button>
            </form>
            {"<p><a class='btn' href='" + result_link + "' target='_blank'>開啟 SearXNG 搜尋結果</a></p>" if q else ""}
            <p><a href="/">Back to Home</a></p>
        </body>
    </html>
    """

@router.post("/search/results")
async def search_results(query: str = Form(...), user_id: str = Form("")):
    return RedirectResponse(url=f"/search?q={query}&user_id={user_id}", status_code=302)