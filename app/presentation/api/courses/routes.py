from fastapi import APIRouter, HTTPException
from pydantic import BaseModel
from typing import Literal
import httpx

from app.application.courses.use_cases import CourseUseCases
from app.infrastructure.courses.memory_repository import CourseMemoryRepository
from app.presentation.schemas.course import CourseCreate, CourseResponse

router = APIRouter(tags=["courses"])
repository = CourseMemoryRepository()
use_cases = CourseUseCases(repository)

CourseStatus = Literal["draft", "approved", "rejected"]

class CourseUpdate(BaseModel):
    title: str | None = None
    description: str | None = None
    credits: int | None = None
    status: CourseStatus | None = None

async def notify_n8n_course_created(course):
    try:
        async with httpx.AsyncClient() as client:
            await client.post(
                "http://localhost:5678/webhook/course-created",
                json={
                    "id": course.id,
                    "title": course.title,
                    "description": course.description,
                    "credits": course.credits,
                    "status": getattr(course, "status", "draft"),
                },
                timeout=5.0,
            )
    except Exception:
        pass

@router.get("/", response_model=list[CourseResponse])
def list_courses():
    return use_cases.get_all_courses()

@router.post("/", response_model=CourseResponse, status_code=201)
async def add_course(course_data: CourseCreate):
    course = use_cases.create_course(
        title=course_data.title,
        description=course_data.description,
        credits=course_data.credits,
    )
    await notify_n8n_course_created(course)
    return course

@router.get("/drafts", response_model=list[CourseResponse])
def list_draft_courses():
    courses = use_cases.get_all_courses()
    return [course for course in courses if getattr(course, "status", "draft") == "draft"]

@router.get("/{course_id}", response_model=CourseResponse)
def get_course(course_id: int):
    course = use_cases.get_course_by_id(course_id)
    if not course:
        raise HTTPException(status_code=404, detail="Course not found")
    return course

@router.patch("/{course_id}", response_model=CourseResponse)
def update_course(course_id: int, course_data: CourseUpdate):
    course = use_cases.get_course_by_id(course_id)
    if not course:
        raise HTTPException(status_code=404, detail="Course not found")

    updated = use_cases.update_course(
        course_id=course_id,
        title=course_data.title if course_data.title is not None else course.title,
        description=course_data.description if course_data.description is not None else course.description,
        credits=course_data.credits if course_data.credits is not None else course.credits,
    )

    if not updated:
        raise HTTPException(status_code=404, detail="Course not found")

    if course_data.status is not None:
        setattr(updated, "status", course_data.status)

    if not hasattr(updated, "status"):
        setattr(updated, "status", "draft")

    return updated
