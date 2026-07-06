from fastapi import FastAPI
from app.presentation.api.schedules.routes import router as schedules_router
from app.presentation.api.users.routes import router as users_router
from app.presentation.api.courses.routes import router as courses_router
from app.presentation.api.notifications.routes import router as notifications_router

app = FastAPI()
app.include_router(schedules_router)
app.include_router(users_router)
app.include_router(courses_router)
app.include_router(notifications_router)

@app.get("/ping")
def ping():
    return {"ok": True}