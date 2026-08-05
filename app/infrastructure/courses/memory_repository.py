from app.domain.courses.entities import Course

class CourseMemoryRepository:
    def __init__(self):
        self.courses = [
            Course(id=1, title="Introduction to Computer Science", description="Basic CS concepts", credits=3, status="approved"),
            Course(id=2, title="Data Structures", description="Learn about arrays, linked lists, trees", credits=4, status="draft"),
        ]

    def get_all(self):
        return self.courses

    def get_by_id(self, course_id: int):
        for course in self.courses:
            if course.id == course_id:
                return course
        return None

    def create(self, title: str, description: str, credits: int):
        new_id = max([course.id for course in self.courses], default=0) + 1
        new_course = Course(id=new_id, title=title, description=description, credits=credits, status="draft")
        self.courses.append(new_course)
        return new_course

    def update(self, course_id: int, title: str, description: str, credits: int):
        for index, course in enumerate(self.courses):
            if course.id == course_id:
                updated_course = Course(
                    id=course_id,
                    title=title,
                    description=description,
                    credits=credits,
                    status=getattr(course, "status", "draft"),
                )
                self.courses[index] = updated_course
                return updated_course
        return None
