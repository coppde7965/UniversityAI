from fastapi import FastAPI

from app.presentation.api.users.routes import router as users_router
from app.presentation.api.schedules.routes import router as schedules_router
from app.presentation.api.courses.routes import router as courses_router


def create_app() -> FastAPI:
    app = FastAPI(title="UniversityAI")

    app.include_router(users_router, prefix="/users")
    app.include_router(schedules_router, prefix="/schedules")
    app.include_router(courses_router, prefix="/courses")

    return app
