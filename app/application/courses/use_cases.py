from app.infrastructure.courses.memory_repository import CourseMemoryRepository

class CourseUseCases:
    def __init__(self, repository: CourseMemoryRepository):
        self.repository = repository

    def get_all_courses(self):
        return self.repository.get_all()

    def get_course_by_id(self, course_id: int):
        return self.repository.get_by_id(course_id)

    def create_course(self, title: str, description: str, credits: int):
        return self.repository.create(title, description, credits)

    def update_course(self, course_id: int, title: str, description: str, credits: int):
        return self.repository.update(course_id, title, description, credits)
