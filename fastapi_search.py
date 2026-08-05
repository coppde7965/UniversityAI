from fastapi import FastAPI, Query
import httpx

app = FastAPI(title="Search Gateway")
SEARXNG_URL = "http://localhost:8080/search"

@app.get("/search")
async def search(q: str = Query(...), categories: str = "general"):
    params = {"q": q, "format": "json", "categories": categories}
    async with httpx.AsyncClient(timeout=20) as client:
        r = await client.get(SEARXNG_URL, params=params)
        r.raise_for_status()
        data = r.json()
    results = []
    for item in data.get("results", [])[:10]:
        results.append({
            "title": item.get("title"),
            "summary": item.get("content") or item.get("snippet") or "",
            "source": (item.get("engines", [""])[0] if item.get("engines") else item.get("source", "")),
            "url": item.get("url")
        })
    return {"query": q, "count": len(results), "results": results or [], "message": "查無符合資料" if not results else "ok"}
