from fastapi import APIRouter, HTTPException
from app.application.courses.use_cases import CourseUseCases
from app.infrastructure.courses.memory_repository import CourseMemoryRepository
from app.presentation.schemas.course import CourseCreate, CourseResponse

router = APIRouter(prefix="/courses", tags=["courses"])
repository = CourseMemoryRepository()
use_cases = CourseUseCases(repository)

@router.get("/", response_model=list[CourseResponse])
def list_courses():
    return use_cases.get_all_courses()

@router.get("/{course_id}", response_model=CourseResponse)
def get_course(course_id: int):
    course = use_cases.get_course_by_id(course_id)
    if not course:
        raise HTTPException(status_code=404, detail="Course not found")
    return course

@router.post("/", response_model=CourseResponse, status_code=201)
def add_course(course_data: CourseCreate):
    return use_cases.create_course(
        title=course_data.title,
        description=course_data.description,
        credits=course_data.credits,
    )
