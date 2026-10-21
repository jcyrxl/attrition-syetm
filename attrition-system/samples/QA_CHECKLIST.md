# QA checklist — importing the PEF

The files below are laid out exactly like the real CCS PEF (the sheet `PEF` with *Evaluation* and *Subject(s) to be enrolled*, plus the `SubjCode Reference Tab`).
They were made from the real form: the student numbers, names and subjects are fictional or taken from the dummy data.
Open **Import Records**, select **all the PEF_* files together**, press *Read Files & Preview*.

| File | Student | What the importer finds | Expected |
|---|---|---|---|
| `PEF_1_existing-student_3rd-to-4th-year.xlsx` | Flores, Nicole I. (23-22-101) | grades of Second Semester 2025-2026 (Year 3, 6 subjects); new semester: First Semester 2026-2027, section IT43 | complete · existing |
| `PEF_2_new-student_2nd-to-3rd-year.xlsx` | Paguio, Maria Lourdes S. (24-22-777) | grades of Second Semester 2025-2026 (Year 2, 7 subjects); new semester: First Semester 2026-2027, section IT32 | complete · new student |
| `PEF_3_freshman_no-evaluation.xlsx` | Abrenica, John Paul R. (26-22-001) | no evaluation; new semester: First Semester 2026-2027, section IT13 | complete · new student, no grades yet |
| `PEF_4_PROBLEM_missing-student-no_INC-grade.xlsx` | Legaspi, Andrea K. | grades of Second Semester 2025-2026 (Year 2, 7 subjects); new semester: First Semester 2026-2027, section IT31 | red: Missing Student No. + "INC" is not a grade |
| `PEF_5_PROBLEM_year-level-clash.xlsx` | Dizon, Kenneth J. (23-22-102) | grades of Second Semester 2025-2026 (Year 2, 7 subjects); new semester: First Semester 2026-2027, section IT41 | red (checked by the system): Year 3 already recorded |
| `PEF_6_PROBLEM_name-exists-with-another-student-no.xlsx` | Ocampo, Camille E. — real Student No. 23-22-103 | grades of Second Semester 2025-2026 (Year 3, 6 subjects); new semester: First Semester 2026-2027, section IT42 | red: this name already exists with another Student No. |
| `PEF_7_NOTE_form-GWA-does-not-match.xlsx` | Bautista, Carlo M. (25-22-555) | grades of Second Semester 2025-2026 (Year 1, 5 subjects); new semester: First Semester 2026-2027, section IT21 | complete, with an amber note |

## Expected preview
- **Counts:** 4 complete files (1 existing, 3 new — the 7th has a note) and **3 need completing** (4, 5, 6).
- The **Import button is OFF** while any red card is left. Try pressing it: it must not be possible.
- **File 4:** type any Student No. (e.g. `25-22-123`) and a grade (e.g. `3.0`) for the INC subject → the card turns normal (green ✓). 
- **File 5:** the card is red (the system found it). Change *Year level* of the evaluated semester to **3rd Year** → it turns amber *Changed — will be re-checked*, and the button turns on.
- **File 6:** the card is red. Replace the Student No. with the real one named in the card → amber → re-checked.
- **File 7:** complete, but listed with an amber note under *the complete files* — it imports as it is.
- Tick/untick *Add students who are not in the system yet*: unticked → the import is refused for the new students ("Not in the system yet").
- Confirm with the Student Data PIN. A wrong PIN is shown on the page and nothing typed is lost.

## After importing all seven
- **Students** shows the school year **2026-2027** (the new semester of every student), sorted A–Z by last name; the 2nd-semester column is empty until its grades arrive; students with a new record have their **risk from the grades just imported**.
- **Dashboard** switches to **2026-2027 · 1st Semester** and the small buttons at the top let you look at **2025-2026** (earlier school year). The grade/attendance chart shows 2026-2027 as a **forecast**.
- **History** now holds **2025-2026** and older as folders (colour-coded by year level). **Start new school year…** (PIN) moves the current one forward later.
- **Prediction** lists only High and Moderate risk students (High together, then Moderate, each A–Z by last name).
- Importing the same files again replaces that semester's grades — it does not create duplicates.

## Must be refused (`negative_tests/`)
| File | Why |
|---|---|
| `not_a_PEF_no-PEF-sheet.xlsx` | no sheet named PEF |
| `PEF_saved_as_csv.csv` | only the Excel PEF (.xlsx) is accepted |
| `PEF_photo.png` | photos can't be read |

## Things the PEF does not contain
- **Attendance** — 100% is used until attendance is entered on the Students page (Edit).
- **The block of the semester that just ended** — the block of the new section is assumed (e.g. IT42 → BSIT-32 for the grades); it can be changed on the card when something else needs fixing.


---

# QA checklist — Classes (attendance and grades recorded in the system)

1. Run `database/migration_classes.sql`. Optional: `database/demo_classes.sql` after `dummy_data.sql`.
2. **Import PEFs** (previous section) → open **Classes**: a class exists for every subject on the PEFs (unclaimed). Each student is in the class of **each subject he or she takes** (and only those). Students → the **Classes** column shows them.
3. **Admin → Class Assignments**: assign the class to a Faculty account (Account-Management Password). Log in as that professor: **My Classes** lists only the assigned classes; **Dashboard, Students, Prediction and Reports show only his or her own students** (a student of another professor's class is not there), and there is no History. Open a class → enter the **Student Data PIN** (once) → the boxes turn on.
4. **Attendance:** add a meeting for *Prelim*; set one student to **A** → the **Absences** cell shows 1. Add a meeting for *Midterm* and set the same student to **A** → 2 → the cell turns amber, the top banner names the student, and the **Dashboard** shows an attendance alert. Set **L** for a third meeting → 2.5; set another **A** → 3.5 → red: *Dropped (Unofficially) limit reached*.
5. **Columns → Prelim:** add `Quiz` **out of 10**, `Quiz` **out of 20**, `Activity` **out of 15**, `Reporting` **out of 20**, and a column named `Case study` (Other…). For one student enter Quiz 1 = **8**, Quiz 2 = **12**, Activity = **9** (the exam is out of 50):
   - class standing = (8+12+9) ÷ (10+20+15) = **64.4%**; type **30** for the exam → exam % = **60%**
   - Prelim grade = 0.6 × 64.4 + 0.4 × 60 = **62.7%**
6. **Grading setup** → Quiz **40**, Activity **60** → the same student: quiz 20/30 = 66.7%, activity 9/15 = 60% → class standing = (40 × 66.7 + 60 × 60) ÷ 100 = **62.7%**; *Use total score* goes back to 64.4%.
7. **Midterm:** add `Quiz` out of 10 and enter **3** → red; the Midterm grade is **30%\*** (no exam yet); **Final Grade** = (0.30 × Prelim + 0.30 × Midterm) ÷ 0.60 — shown red while below 75.
8. A score of **4 out of 10** is red; **5 out of 10** is not. Two red scores, or 2 absences, mark the student in red.
9. A score above the items (12 out of 10) is refused.
10. Change the **items** in a column header → everything recalculates.
11. After the first entry, open **Students → View**: the subject appears in the current year with the Final Grade so far; **Prediction** shows the new risk; the bell shows a notification when a student becomes **High**.
12. **Students → Edit**: the subject recorded in Classes is locked (padlock).
13. **Class list → remove** a student → he or she stays out after reloading; another Faculty account sees the class as **view only**.

14. **Dashboard** (Admin / Program Head): *Where to Look First — by Section* shows Prelim; after scores exist in Midterm both Prelim and Midterm bars appear. Sections below 75% are red; the Leave-of-Absence graph is gone. As a professor the same graph shows only your own classes.
15. **Classes** page: search by subject / section / professor; filters for school year, semester, section, professor, and *with students needing attention / absence alerts / not assigned*.
16. **Students** page lists only enrolled and on-leave students — mark a student *Graduated* and he or she leaves the list (and appears in History).
17. After importing the dummy data, open the **Dashboard** once: every risk is recalculated, so a student with good grades is no longer *High* and a failing one is no longer *Low*.

18. **Admin** logs in → lands on **User Management**; the sidebar has only User Management and Class Assignments; Dashboard, Students etc. send him or her back. Class Assignments also has the *School year* card (moves the year forward, optionally marks 4th-years Graduated).
19. **Program Head**: Dashboard (whole College), Students, History, Prediction, Reports — no Classes, no buttons that change anything. **Students → View** a student: Overview, Attendance (every meeting), Quizzes & scores, Grades, **History since 1st year** and **Risk over time**. A graduated student's whole history is there.
20. **Clickable graphs**: tap the *High* slice → the students at that level (each opens the student page); tap a section → its students; tap a date in *Attendance* → who was absent; tap a quiz → the students below 50%; tap a grade bar → the students in it. A professor's graphs contain only his or her own subjects.
21. **Attendance graph** (professor with a class where one student has 3 absences in Prelim): the student is at the top, red; he or she is also under *Attendance alerts*.
22. **Reports** no longer has *Were the predictions right?*.

23. **Header**: scroll any long page — the header (system name left, bell + user right) stays. There is no Back button. The ☰ button folds the sidebar to icons (and remembers it).
24. **Class sheet**: grouped header (Attendance | Class standing | Exam | Term); name column and headers stay while scrolling; **Meeting** and **Column** open small cards; type a score and press **Enter** → next student; copy 5 numbers in Excel and **paste** into a score cell → they fill downward. Typing 8.25 is refused (whole or .5 only). A class standing of 64.33% shows as 64.5%.
25. **Class list → Add students**: paste `23-22-028 [Tab] Dela Cruz, Juan A.` lines (or upload a .csv) → *Check the list* → *Add*. Existing students are only added to the class; a name that already has another number is marked Problem.
26. **Class Assignments**: classes are grouped per subject with an ID. In *Introduction to Computing* tick BSIT-11 and BSIT-12 → choose Prof A → *Apply to ticked* → tick BSIT-13 → Prof B → *Apply* → *Save assignments*. Both professors now have that subject, in different sections.
27. **Archive**: Admin → Class Assignments → *Semester*: with incomplete grades the list shows the classes still missing a Final Grade and the button is disabled. Run `demo_complete_grades.sql`, reload, archive → the system is on the next semester; the old classes are view only (professors can no longer record in them); Dashboard / Classes / Assignments show only the new semester.
28. **E-mail**: login page → *Forgot password?* → User ID + e-mail on the account → the code (shown on screen in demo mode) → new password → log in. In *Privacy Settings*, changing the login password needs the e-mail code; the same for the PIN and the Account-Management Password. The e-mail is saved in `storage/outbox/` in demo mode.
29. **Backup Admin**: User Management shows the "only Admin" note until a second Admin exists; the second Admin can reset the first one's password.
30. **Prediction**: *Main reasons* chips, ▲/▼ change, order by risk, **Download the at-risk list (CSV)** opens in Excel with the reasons.

31. **Class Assignments**: in a subject, tick two sections → choose a professor → *Apply to ticked* → the two rows turn blue and "unsaved changes" shows → *Save assignments* (password) → "2 classes assigned". The professor's Dashboard / My Classes now show them.
32. **Dashboard attendance**: two separate sections — *who is absent* (single lines) and *week by week* bars with the newest week at the right edge, already in view; tapping a bar lists who was absent.
33. **Dashboard preview**: scroll down the Dashboard, tap a student anywhere (a bar, a table row, inside the window of a graph) → a panel slides in from the right; close it → the Dashboard is at the same place.
34. **Add students**: Students (or My Classes) → *Add students* → pick the class → paste lines → *Check the list* → *Add*.
35. **Reports as a professor**: only *Risk breakdown*; no Overview / Requests / LOA / file-a-request.

36. **Class Assignments** (Admin): tick all sections of a subject → choose a professor → *Apply to ticked* → a floating **Save changes** bar appears → *Save changes* → the password pop-up (middle of the screen) → "N classes assigned". Delete a class: the trash icon → password pop-up → deleted (never "Nothing changed").
37. **Close the semester** (Program Head, History): the table by professor with Prelim / Midterm / Pre-Finals / Final counts and the chips *All grades in / In progress / Not yet encoded*. *Clear class* is offered only for a complete class (PIN pop-up). *Archive the semester* is disabled until every class is complete; then it needs the PIN **and** the word ARCHIVE. After archiving, Dashboard / Classes show the next semester and the old classes are view only for the professor.
38. The Admin has **no** archive anywhere. The Program Head must set a PIN in Privacy Settings first.
39. **Students → Add students** (Faculty): upload a .docx whose table lists the students, or a JPG/PNG screenshot (needs internet; check the text), then *Check the list* → *Add*. My Classes has no add-students box.
40. **Prediction as a professor**: *At-risk in my classes* (bars per class). As the Program Head: *At-risk by year level* is still there.
41. **Dummy data**: 1,040 students, sections BSIT-11 to BSIT-18, 21 to 26, 31 to 37, 41 to 45; each 1st-year block has 40 students. *prepare_demo.php* shows its progress and finishes with "N students have a risk now".

42. **Dashboard at 100% zoom** shows, without scrolling sideways: the donut, the section line chart and the third card; below them the attendance line and *Who is absent*. *See more graphs* reveals quizzes, grades, history (Program Head) or the attention list (professor).
43. Tap a ring on the section line → the students of that section (High risk first) → tap a name → the preview panel. *See all N* (sections) → tap a section → its students → *← Back*.
44. Tap a ring on the attendance line → who was absent / late / excused that week. A red ring = a week under 80%.
45. Tap a ring on *Quizzes and activities* → the classes with their average → tap a class → the students below 50%. Tap a ring on *Grades* → the students failing that term.
46. As the Program Head, *From history to now*: tap a past year → who left and why; tap the amber ring → who is at risk now.
47. On **Students**, **Prediction** and **Reports**, a student's *View* opens the preview panel (page does not change); the Esc key or the ✕ closes it.

48. **Student requests (Program Head)**: Reports → Requests → *File a Request*: choose the student, the type (Leave of Absence / Dropped (Unofficially) / Honorable Dismissal / Shifted), school year, semester, date the letter was filed and the reason → the PIN pop-up → saved. The student's status changes (he or she leaves *Students*), the Requests list and the reasons graph show it, and a mistake can be edited or deleted (PIN). As a **Faculty** account, Reports has no Requests section at all; as the **Admin**, there are no student pages.

49. **Class page**: open My Classes → a class: the sidebar is icons, every student is visible, **Scores | Attendance** switch per term, *Abs.* beside the name; *Unlock to edit* asks for the PIN in a pop-up.
50. **Notification read**: as Program Head or Faculty, click an unread bell item: it fades at once and the red dot disappears when none is left.
51. **Request form (Program Head)**: Reports → Requests → *Request form*: only Leave of Absence and Honorable Dismissal; after saving, the student's status changes and the list shows it. Faculty and Admin cannot open this page.
52. **Shifted (Program Head)**: Students → the status drop-down of a student → Shifted (PIN) → the student is not counted as attrition; set back to Enrolled the same way.
53. **Dropped (Unofficially) (Faculty)**: class → Summary → *Mark Dropped (Unofficially)* (PIN). As Program Head: Reports → Requests shows it (recorded by the professor), the bell shows a notice, the status graph counts it. The professor can *Undo* only his or her own.
54. **Excel only**: Students → Add students: upload a .xlsx (works); a .docx, .jpg or .csv is refused with a clear message.

55. **Request Form menu (Program Head)**: the sidebar has *Request Form*; the form has 4 steps; saving asks for the PIN in a pop-up; a new request returns to the form with a green message and a *View in Reports* link. Faculty and Admin do not see this menu item.
56. **Class page height**: open a class as Faculty on a normal laptop screen: the first students are visible without scrolling; the *Unlock to edit* button is in the header; hover the *absence alerts* pill for the names; in Summary, hover a long *Needs attention* note to read all of it.

57. **Log-in**: type a different User ID and a wrong password: the page comes back with **that** User ID in the box (not an older one).
58. **Add Account (Admin)**: no Account-Management Password field; the User ID box takes only digits and dashes and refuses Ctrl+V, right-click paste and drag-and-drop; typing `12` and saving shows *User ID must be 4 to 20 characters long* **inside the window**, which stays open; a valid ID saves and the table shows the new row. The table has *Created by*, *Updated by* and *Updated* columns (editing or resetting an account fills the last two).
59. **Subjects (Admin)**: *Add Subject* takes the name only; a duplicate shows its error in the window; a subject used by a class cannot be deleted. The Admin has no Class Assignments page.
60. **Class Assignments (Program Head)**: *New class* needs a subject (from the Admin's list), a subject code, a section and (optionally) a professor, then the PIN pop-up; the professor sees the class in My Classes. As Admin or Faculty, `assignments.php` sends you away.
