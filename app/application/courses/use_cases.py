from typing import List, Optional

from app.domain.courses.entities import Course
from app.application.courses.repository import AbstractCourseRepository


class CourseUseCases:
    def __init__(self, repository: AbstractCourseRepository):
        self.repository = repository

    def get_all_courses(self) -> List[Course]:
        return self.repository.get_all()

    def get_course_by_id(self, course_id: int) -> Optional[Course]:
        return self.repository.get_by_id(course_id)

    def create_course(self, title: str, description: str = "", credits: int = 3) -> Course:
        return self.repository.add(title, description, credits)

    def delete_course(self, course_id: int) -> bool:
        return self.repository.delete(course_id)
