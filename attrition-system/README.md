# Web-Based Student Attrition Risk Prediction System With Data Analytics For College of Computing Studies

Predicts which BSIT students of the College of Computing Studies (Universidad de Manila) are at risk of attrition — from their **grades and attendance** — so the College can help them early. PHP + MySQL + Python (scikit-learn, Logistic Regression).

## Users (three roles)
| Role | Who | Can do |
|---|---|---|
| **Admin** | system administrator | **User Management** (gives out the accounts; the table shows who created and last updated each account and when) and **Subjects** (adds the subject *names* only — no subject code, no section, no professor). **No graphs, no student pages, no class assignment, and it does not archive anything** — User Management is home. Adding an account needs no extra password; editing, resetting and deleting ask for the Account-Management Password. |
| **Program Head** | the program head | Sees **everything** — and, **with a PIN**, **records the students' requests** (leave of absence, dropped, honorable dismissal, shifted — from the letters submitted to the College) in *Reports → Requests*, and closes the semester (History → Close the semester): Dashboard of the whole College, **Students** (open a student to see every quiz, activity, attendance mark, grade of every term and class, the whole history since 1st year, the risk over time and the requests), History, Prediction, Reports. **No Classes page** — it is all under Students. Login password only. |
| **Faculty** | a professor | Records in **My Classes** (the classes the Program Head assigned). **Dashboard, Students, Prediction and Reports show only his or her own subjects and students** (nothing about other subjects or their grades). No History. Can import PEF files (optional). Student Data PIN unlocks recording for an hour. |

The Registrar's Office is not a user. Every role also has Profile (photo, e-mail, contact number) and Privacy Settings.

## Sidebar (by role)
- **Admin:** User Management · Subjects
- **Program Head:** Dashboard · Students · Class Assignments · Request Form · History · Prediction · Reports
- **Faculty:** Dashboard · Students · My Classes · Prediction · Reports (own subjects and students only)
Every page except the Dashboard has a **Back** button.

## Setup (XAMPP)
1. Import `database/schema.sql` (creates `ccs_attrition_system`). Edit `config/db.php` if needed. The PHP **zip** extension must be on (`extension=zip` in `php.ini`) — Excel files are read with it.
2. Open `create_admin.php` once, create the **Admin** account, then **delete that file**.
3. Admin → Privacy Settings → set the Account-Management Password (and, if wanted, the Default Password).
4. Admin → User Management → create **Faculty** accounts (a temporary password is shown once; they must change it at first login).
5. Each Faculty member → Privacy Settings → set the **Student Data PIN** (6 digits).
6. Optional test data: import `database/dummy_data.sql`. Optional: put a campus photo at `images/campus.jpg`. Make `uploads/avatars/` writable.
7. Python (only to retrain the model): `pip install scikit-learn pandas mysql-connector-python`, then `python python/train_model.py`.

## Moving from the older version (existing database)
Run **`database/migration_classes.sql`** once (adds Classes, lets a year exist before it has grades) — then the older changes below only matter if you skipped them. For a brand-new database, `schema.sql` already has everything.

```sql
-- two roles: Faculty and Admin
ALTER TABLE users MODIFY role ENUM('registrar','program_head','faculty','admin') NOT NULL DEFAULT 'faculty';
UPDATE users SET role = 'faculty' WHERE role IN ('registrar','program_head');
ALTER TABLE users MODIFY role ENUM('faculty','admin') NOT NULL DEFAULT 'faculty';

-- a student can be enrolled in a school year before any grade exists
ALTER TABLE student_records MODIFY gwa DECIMAL(5,2) NULL, MODIFY attendance_rate DECIMAL(5,2) NULL;

-- no longer used
DROP TABLE IF EXISTS import_pending;
```
Names are stored as **Last Name, First Name M.**; Student Nos. are kept as written on the PEF (e.g. `23-22-028`). Everything else (`student_cases`, `system_settings`, `notifications`, PIN / lockout columns) is in `schema.sql` — for an old database it is simplest to re-import `schema.sql` into a fresh one.

## Classes — attendance and grades recorded in the system
Faculty record attendance and grades **in the system itself** (no other software), so the data is collected every meeting and the risk is predicted again with every entry.
- **The class list builds itself, student by student.** A class = one subject, one section, one semester. Every enrolled student who takes that subject is in it automatically: a student whose subjects are known (from the imported PEF — "Subjects To Be Enrolled") is in the class of **each subject he or she takes**, even from another section; a student with no subject list follows the classes of his or her section. Importing PEFs also **creates the classes** (not assigned to a professor until the Admin assigns them under **Class Assignments**; the Admin can also create a class there). A student taken out of a class by hand stays out. The Students page shows each student's classes.
- **One tab per term** — Prelim · Midterm · Pre-Finals (it opens on the term in progress) — then **Summary**, **Grading setup** and **Class list**. Each term tab has its meetings (attendance), the columns the professor added, the class standing, the exam, the term grade and a class average under every column. Search a student, show only those who need attention, press **Enter** to go to the next student, and download the Summary as CSV.
- **Attendance** — one small box per meeting with `/` (present, the default), `A` (absent = 1), `L` (late = ½), `E` (excused = ½). **Absences add up over the whole semester** (Prelim to Pre-Finals): **3 or more = Dropped (Unofficially)**, so the sheet, the Classes list and the Dashboard alert already at **2**.
- **Columns** — the professor adds whatever is graded: Quiz, Activity, Recitation, Reporting, Seatwork, Assignment, Laboratory, Project, or **any name typed in**, each with its number of **items** ("out of 10"); then every student's **score** is typed in. A blank score = not entered yet; type 0 for a missed one. The items of a column can be changed at any time.
- **Grades, like a spreadsheet:**
  - **Class standing %** = total score earned ÷ total items — or, if the professor sets **weights** under *Grading setup* (e.g. Quiz 40%, Activity 30%, Recitation 30%), the weighted average of those kinds.
  - **Term grade** = **60% class standing + 40% exam**.
  - **Grade so far** = 30% Prelim + 30% Midterm + 40% Pre-Finals over the terms that exist (a term with no exam yet shows the class standing so far, `*`). It flags a failing student **at once** — even during Prelim — and feeds the prediction.
  - **Final Grade** = the same, but shown **only when Prelim, Midterm and Pre-Finals are all complete** (class standing and exam in each). Until then it says *Not yet*.
- **Needs attention (red)** — a score below **50%** of its items (e.g. below 5 out of 10); 2 or more such scores; **2 absences**; or a Final Grade so far below **75%**. Marked in red on the row, summarised at the top and in the *Needs attention* column.
- **Automatic prediction** — every mark and score is saved at once, copied into the student's subject and year records (so Students, History, Reports and Prediction all see it), and the student's risk is predicted again. The sheet shows each student's current risk.
- Opening a class asks for the **Student Data PIN once** (unlocked for one hour). Only the professor the **Admin assigned** the class to can open it and record in it. (The Program Head has no Classes page: every score, attendance mark and grade is under **Students → View**.) Rows recorded in Classes cannot be typed over in the student's Edit window.
- Optional demo: after `dummy_data.sql` and the Faculty accounts exist, run **`database/demo_all_classes.sql`** — a class for every section of the enrolled students (5 subjects per year level), dealt out to **all Faculty accounts** so each professor handles different subjects in different sections, with attendance and scores already in. Add more Faculty accounts and run it again to spread the classes over everybody. It replaces `demo_classes.sql`.

A **Faculty** Dashboard shows only the professor's own classes: risk of his or her students, the by-section graph, a list of his or her classes with the number of students who need attention, absence alerts and the students who need attention. A **Program Head / Admin** Dashboard shows the whole College.

**Predictions are refreshed once** when the Program Head first opens the Dashboard after this update (so the risk everywhere matches the grades now on record), and again for a student every time something is recorded for him or her. They are also refreshed for a student each time something is recorded for him or her, and for the whole class whenever a class is opened.

## What each page does
- **Dashboard** (Program Head: the whole College · Faculty: only his or her own classes) — every graph can be **tapped**: a window shows the students behind it (each name opens the student's own page) and links to the right list.
  - *Risk of Attrition* (slices → the students at that level), **Where to Look First — by Section** (average grade of each section for the term in progress; once Midterm starts Prelim and Midterm appear together, then all three; a section is a link to its students), *Reasons for Leave of Absence* (Program Head) / *My Classes* (Faculty).
  - **Attendance — from the start of the school year to today**: who is absent (absence points, amber at 2, red at 3) and the attendance rate on every meeting date (tap a date to see who was absent, late or excused).
  - **Quizzes and activities**: the class average of each, hardest first; tap one for the students below 50%.
  - **Grades**: per class, failing / passing / doing well for the term in progress; once all three terms are complete it becomes the **Final Grade** summary of the whole term.
  - Program Head only: **From history to now** (attrition rate of each earlier school year, and how many are at risk right now), attendance alerts, highest-risk students.
- **Current school year** — follows the calendar (June–December = 1st semester; January–May = 2nd; October 2026 = **2026-2027, 1st Semester**), or whatever the Faculty chose with *Start new school year*. A student is in a school year as soon as a PEF enrols them in it.
- **Students** (view only) — the current school year only, **enrolled or on-leave students only** (graduates, dropped, dismissed and shifted students are in History), **flat list A–Z by last name** (no folders). Filters: Year Level, Section, Status, Risk + search (no page reload). 1st and 2nd semester GWA shown. **Edit** works on the current school year only — the records of earlier school years are shown **view only** (they are what the prediction was based on), in the Edit window and on the server.
- **History** — every earlier school year as folders (school year → section), **colour-coded by year level** (1st teal · 2nd sky · 3rd blue · 4th navy). On the folder view the search box and the School Year / Section filters read **folder names only**; open a folder to see the students (A–Z by last name) with filters School Year, Section, Status, Risk (risk *at that time*) and search by name or ID. **Start new school year…** (PIN): moves the finished school year to History — nothing is copied or deleted, History simply shows every school year before the current one — and optionally marks the enrolled 4th-year students Graduated. A reminder banner appears on the Dashboard and Students pages when the calendar says a new school year has started.
- **Import PEF (optional)** — a small button on the Students and Classes pages for those who prefer to bring in earlier records from the students' PEFs. See below.
- **Prediction** — graphs first (at-risk by year level, by section, the subjects the at-risk students failed most, and how High / Moderate / Low compare in grades, attendance and failed subjects), then the list of **High and Moderate** students only — High together, then Moderate, each **A–Z by last name**. Low-risk students are not listed.
- **Reports** — data first: *Student Status*, **Attrition rate per school year** (out of every 100 students, how many dropped out), **Attrition cases by year level**, **Why students dropped out** (the reasons in their letters, and in which semester), **Were the predictions right?** (students by the risk they were given, and the percent of each group that later dropped out or was dismissed), status by year level → **Requests** (Leave of Absence, Honorable Dismissal, Dropped (Unofficially), Shifted — filed from the student's letter; reasons graph with pop-up; re-enroll) → **Risk breakdown**. A *What do the words mean?* box explains every term. *Attrition* = Dropped (Unofficially) + Honorable Dismissal; graduates, shifted students and students on leave are **not** attrition.

## How the risk is predicted (what to tell the panel)
- **Method:** Logistic Regression (scikit-learn for training, plain PHP for scoring).
- **Four inputs**, calculated from **everything on record** — the **current** school year (the grades and attendance recorded in Classes so far) **and every earlier year, even when it is already in History** (a 4th-year student is judged on year 1 to year 4): **grades** and **attendance** = 70% current + 30% average of the earlier years; **failed subjects** = all failed so far; **grade trend** = current grades − average of the earlier years (going down raises the risk). It is predicted again with every saved entry, so it keeps moving while the semester is going.
- **Calculation:** `score = intercept + w1·grades + w2·attendance + w3·failed + w4·trend`, then `chance of attrition = 1 / (1 + e^−score)`. Current weights: intercept 12.50, grades −0.12, attendance −0.05, failed subjects +0.60, trend −0.04 (stored in `model_settings`).
- **Levels:** High ≥ 70% · Moderate 40–69% · Low < 40%. Only High and Moderate students are listed on the Prediction page.
- **Be honest about the weights:** the numbers above are **starting values chosen by the developers**, not yet learned from UDM's own history. `python/train_model.py` learns them from students who dropped out vs stayed once enough school years are in the system; until then, the percentages are indicative, and the `accuracy` value in `model_settings` (91.0) is only a placeholder — do not quote it as a measured result. The Reports page (*Were the predictions right?*) shows how the prediction actually performed on past school years.

## Importing the PEF (optional)
The PEF has two parts and both are read: the **Evaluation** (the semester that just ended: grades → saved, and the risk is predicted from them) and **Subjects To Be Enrolled** (the new semester and its **section**, e.g. `IT42` = BSIT-42 → the student appears in the new school year).
1. Select the PEF files → *Read Files & Preview*.
2. Every file with something missing or wrong is a **red card with a "!"** and a note; type or choose what is missing right there. The card turns normal when it is complete.
3. **The Import button stays off until every card is complete**, and the server refuses an incomplete upload too — it is all or nothing. Files with only a note (amber) are complete and import as they are.
4. Confirm with your **Student Data PIN**. Grades of the same semester are replaced; the risk is recalculated.

What the PEF does **not** contain, and what the system does about it:
- **Attendance** — 100% is used until attendance is entered on the Students page (Edit). Without attendance, the risk reflects grades only.
- **The block of the evaluated semester** — the new section's block is assumed (changeable on the card).
- **Grades are on the 4.00 scale** (4.00 highest) and are converted to percentages for the risk model: **1.00 = 75% … 4.00 = 100%, in a straight line; below 1.00 is failing.** *This conversion is an assumption* — replace `gradeToPercent()` in `config/academic.php` with the official UDM grading table if it differs. A grade of 5.00 is read as failed.

## Security
Login password; Faculty also need the personal Student Data PIN for every change; Admin needs the Account-Management Password. Wrong PIN/password: 6 tries → 15-minute lock, 3 → 30 minutes, 3 → 24 hours + automatic logout. Admin-triggered password reset sets the Default Password and forces a change at next login.

## Testing
`samples/QA_CHECKLIST.md` — seven PEF files in the real layout (complete, new student, freshman, and three that must be completed) and three files that must be refused.

## Reports
Focused on what the College needs to act: **who is at risk** (risk breakdown by section, the high-risk referral list), **why students left** (reasons), the attrition rate per school year and by year level, and the **requests** (leave of absence, dismissal, drop, shift). The "Were the predictions right?" panel was removed. A professor's Reports show only his or her own students.


## What changed after the technical adviser's review
- **Class Assignments** is a table **per subject** (each class has an ID). Several professors can teach the same subject in different sections: tick the sections, choose the professor, *Apply to ticked*, then *Save assignments*. A "who handles what" overview sits above. Only the current semester is shown; **no school-year / semester filters** anywhere (Dashboard, Classes, Assignments).
- **Archive is automatic and safe.** The Admin presses *Archive this semester* (Class Assignments → Semester) — it works **only when every grade is complete** (each student of each class has a Final Grade). The semester becomes archived (its classes are view only; the Program Head keeps the history) and the system moves to the next semester (2nd → next school year's 1st). To try it, run `database/demo_complete_grades.sql` after `demo_all_classes.sql`.
- **Class list (masterlist)** — Classes → *Class list* → *Add students*: paste the names from the faculty portal or Excel (or upload a .csv/.txt), one student per line with the student number; *Check the list* shows what will happen (new / already in the system / problem) before anything is added. PEF import is still there, optional.
- **Grades are whole numbers or .5** — scores, items, class standing, exam, term grade, Final Grade, GWA. (A score of 8.25 is refused; 64.33% is shown as 64.5%.) Percentages are kept to that step everywhere, so what is shown is what is counted.
- **The class sheet is a spreadsheet**: grouped columns (Attendance | Class standing | Exam | Term), frozen name and headers, a compact bar (tabs · find · needs attention), small “+ Meeting / + Column” cards instead of loose buttons, **Enter / ↓ move down a column, a column copied from Excel can be pasted**, class average under each column, many students scroll inside the sheet.
- **One header that stays**: the system name at the left, the bell and the user (photo + name) at the right, always visible while scrolling; **no Back button**; the menu button folds the sidebar to icons so wide tables get the room.
- **E-mail for passwords**: *Forgot password?* on the login page (User ID + the e-mail on the account → 6-digit code → new password) for **every role, the Admin included**; changing a login password, a Student Data PIN or the Account-Management Password asks for a code sent to the account's e-mail. Set the e-mail in *User Profile*. Out of the box the system runs in **demo mode** (no mail server needed: the e-mail is saved in `storage/outbox/` and the code is shown on screen). For real e-mail, open `config/mail.php`, set `MAIL_MODE` to `'smtp'` and fill in `SMTP_USER` / `SMTP_PASS` (a Gmail *App password*). The SMTP sender is untested on a live server — try it once before the defense.
- **Backup Admin**: User Management warns when there is only one Admin. A second Admin account can reset the first one's password (and the other way round); the last Admin can never be deleted.
- **Prediction** now answers *who, why, and what moved*: each at-risk student has **Main reasons** (the model's own formula split by input — low grades, low attendance, failed subjects, grades going down), a ▲/▼ for how the risk moved since the last check, the list is ordered by risk, and **Download the at-risk list (CSV)** hands the list to the people who will act on it. (Intervention itself is outside the study.)


## Second round of fixes
- **Class Assignments — "Apply to ticked" now works.** The page was missing the script that its password check needs, so the whole page script stopped and nothing was applied (the save then said "Nothing changed"). Fixed; the page also keeps working if that script is ever missing.
- **Dashboard attendance has two sections of its own** (nothing is squeezed side by side any more): *who is absent* (one line per student) and *attendance rate, week by week* — a school year of meetings fits as weeks, and the **latest week is at the right and already in view**.
- **A student opens in a side panel, not another page.** On the Dashboard, tapping any student (a list, a bar, the window of a graph, the bell) opens a preview — grades per term, final, absences, attendance, scores below 50%, what needs attention — over the Dashboard; close it and you are exactly where you were. *Open the whole record* is there when you want the full page. Tapping a section in the section graph opens its students in a window too.
- **Add students** has its own page (button *Add students* on Students and My Classes; also inside a class under Class list): choose the class, paste or upload the list from the portal / Excel, *Check the list*, *Add*. A blank .csv template can be downloaded.
- **A professor's Reports** has no requests / leave of absence / file-a-request any more — only the risk breakdown of his or her own students. (Nobody files requests in the system now; the Program Head can only view them.)


## Third round (review of the technical adviser and the class record)
- **The semester is closed by the Program Head, with a PIN — not by the Admin.** *History → Close the semester*: a table **by professor** shows who has encoded their grades — **by term** (Prelim · Midterm · Pre-Finals · Final, as *students complete / students*) with a status chip: **All grades in · In progress · Not yet encoded**. A class whose grades are complete can be **cleared** (its grades become final and it is archived: view only; *Reopen* undoes it). **Archive the semester** works only when every class is complete; it asks for the PIN **and** to type `ARCHIVE`, moves the system to the next semester (and, if chosen, marks the 4th-year students Graduated). The Program Head sets the PIN in Privacy Settings, like Faculty.
- **A password pop-up for every save**: Class Assignments (save, delete, new class) and the semester actions ask in a pop-up in the middle of the screen — nothing to scroll to. Unsaved assignment changes show a floating **Save changes** bar; assignments are sent explicitly to the server and a message says exactly what happened (this also removes the earlier "Nothing changed").
- **Professional look**: one design system (`assets/ui.css`): colours, spacing, header, cards, tables, forms, buttons, badges, modals, toasts, the save bar, the password pop-up (`assets/ui.js`).
- **Add students (Students page only)**: no adding inside My Classes any more. Paste, upload a **.csv / .txt / Word (.docx)**, or a **picture (JPG / PNG)** of the list (read on your computer by an OCR library from the internet — *check the text before adding*; an old .doc must be saved as .docx).
- **Prediction for a professor**: *At-risk in my classes* replaces *At-risk by year level* (that summary stays with the Program Head).
- **Sections go to block 8** (BSIT-11 … BSIT-48).
- **New dummy data by batch**: batch 2023 (5 blocks, now 4th year), 2024 (6, 3rd year), 2025 (7, 2nd year), 2026 (8, 1st year); **40 students per block** at the start = 1,040 students in all, with the people who left (LOA, dropped, honorable dismissal, shifted) and their letters. Import order: `schema.sql` (or `migration_classes.sql`) → `dummy_data.sql` → create the Faculty accounts → `demo_all_classes.sql` (about 156 classes for the 1st semester of 2026-2027, dealt out to every Faculty account) → open `registrar/prepare_demo.php` once (copies the results into the records and predicts everybody; it shows its progress). `demo_complete_grades.sql` finishes the semester so the archive can be tried.


## Fourth round — a Dashboard that fits on the screen
- **Line charts** (a ring on every point, grid and axes) replace the bars wherever something changes over time: *Where to look first* (a line per section across Prelim → Midterm → Pre-Finals, with the passing line), *Attendance rate week by week*, *Quizzes and activities* (class average in the order given), *Grades term by term* (average and % failing; the Final point appears when every term is complete), *From history to now* (attrition per school year, and at risk now). The donut for risk stays.
- **Compact**: the first screen has the risk donut, the sections that need attention, a third card (Program Head: reasons for leave · professor: My Classes — the top 5), and the attendance line with *Who is absent* (top 6). The rest is behind **See more graphs** (it remembers if you left it open). Long lists show a few rows and a **See all N** button.
- **Every graph is tappable and answers in a window** — never a new page: a point, a slice or *See all* opens the data (who was absent that week, the students below 50% in a quiz, the students failing a term, who left in a school year and why …). Rows can open another window (a section → its students) with **← Back**. A student opens a **preview panel** at the side — on **every page** (Dashboard, Students, Prediction, Reports, History, the bell), not only the Dashboard; the Program Head's preview also has the history since 1st year and the risk line. *Open the whole record* is still there.


## Fifth round — student requests belong to the Program Head
- **The Program Head records and manages the students' requests** — *Leave of Absence, Dropped (Unofficially), Honorable Dismissal, Shifted* — in **Reports → Requests → File a Request**, from the letters submitted to the College, always with the **PIN**. A request changes the student's status (so he or she leaves the Students list and the risk scoring) and feeds the attrition graphs and the reasons. The professors (Faculty) do **not** see or file requests; the Administrator has no student pages.
- Set the Program Head's PIN in Privacy Settings first (the same PIN also protects clearing a class and archiving a semester).

## Sixth round — class page, status roles, Excel-only class list
- **My Classes page** redesigned for room: the sidebar folds to icons, a compact header (class, section, Locked chip, Export CSV), a **View-only … Unlock to edit** banner (the PIN is asked in a pop-up), and each term has a **Scores | Attendance** switch so every student and grade is in view; the *Abs.* column sits beside the name.
- **Notifications** are marked read the moment they are clicked (the red dot goes when none is left).
- **Who handles which status:** the **Program Head** records *Leave of Absence* and *Honorable Dismissal* on the separate **Request form** page (Reports → Requests → Request form) with the PIN, and classifies a student as **Enrolled or Shifted** on the Students page (no form for Shifted). The **Faculty** records only *Dropped (Unofficially)*: Summary tab → *Mark Dropped (Unofficially)* (PIN), and can take back their own. A professor's drop appears at once in the Program Head's Reports → Requests, the status graphs and the bell.
- **Class list upload is Excel only** (.xlsx, or cells copied from Excel). Word files and pictures are no longer read.
- Run `database/migration_requests.sql` once on an existing database.

## Seventh round — Request Form menu, a higher class sheet
- **Request Form** is now its own item in the Program Head's menu (not inside Reports). It is a themed page with four steps (type, student, when, reason) and asks for the PIN in a pop-up when you save. Reports keeps only the *list* of requests (edit, re-enroll, delete).
- **Class page, higher and wider:** one slim header row (back arrow, class, *View-only / Unlock to edit*, Export CSV); the attendance alert is a small pill beside the search box (hover for the names); the first student now starts near the top of the screen. The *Needs attention* note shows two lines and the whole note on hover.

## Eighth round — the Admin adds subjects, the Program Head assigns classes
- **Class Assignments moved to the Program Head** (own menu item, PIN). A class is made from a subject the Admin added: the Program Head gives the **subject code**, chooses the **section** and assigns the **professor**. The Admin has a new **Subjects** page (name only) and can no longer make or assign a class.
- **User Management:** new columns *Created by*, *Updated by*, *Updated*; **Add Account** needs no Account-Management Password; the **User ID** accepts only typed numbers and dashes (no paste, copy, cut or drag), and every error (for example *User ID must be 4 to 20 characters long*) shows **inside the Add Account window**, which stays open until the account is saved or Cancel is pressed.
- **Log-in:** after a wrong password the User ID you typed stays in the box.
- Run `database/migration_roles.sql` once on an existing database (adds the audit columns and the `subjects` table, filled from the existing classes and records).
