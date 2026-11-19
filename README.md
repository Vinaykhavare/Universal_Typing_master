<div align="center">

<!-- तुम्ही तुमचा logo.jpg रिपॉझिटरीमध्ये अपलोड केल्यास खालील ओळ अनकमेंट करू शकता -->
<!-- <img src="logo.jpg" alt="Universal Typing Master Logo" width="180" /> -->

<img src="https://readme-typing-svg.demolab.com?font=Orbitron&weight=700&size=32&duration=2500&pause=800&color=00D9FF&center=true&vCenter=true&width=850&lines=UNIVERSAL+TYPING+MASTER;GCC-TBC+Typing+%26+Exam+Portal;Practice+Smart+%7C+Type+Fast!" alt="Animated Title" />

<h3>⚡ A Complete Maharashtra GCC-TBC Typing Practice & Examination Platform ⚡</h3>

<img src="https://img.shields.io/badge/PROJECT-UNIVERSAL%20TYPING%20MASTER-00D9FF?style=for-the-badge&labelColor=071A33" />
<img src="https://img.shields.io/badge/PATTERN-GCC--TBC%20EXAM-16A34A?style=for-the-badge&labelColor=071A33" />

<br/>

<img src="https://img.shields.io/badge/PHP-8.x%20Backend-777BB4?style=flat-square&logo=php&logoColor=white" />
<img src="https://img.shields.io/badge/MySQL-Database-4479A1?style=flat-square&logo=mysql&logoColor=white" />
<img src="https://img.shields.io/badge/HTML5-Frontend-E34F26?style=flat-square&logo=html5&logoColor=white" />
<img src="https://img.shields.io/badge/CSS3-Responsive%20UI-1572B6?style=flat-square&logo=css3&logoColor=white" />
<img src="https://img.shields.io/badge/JavaScript-Vanilla%20JS-F7DF1E?style=flat-square&logo=javascript&logoColor=black" />

</div>

---

## 🔥 About The Project

**Universal Typing Master (UTM)** is a comprehensive web-based typing practice and examination platform tailored for **Maharashtra GCC-TBC (30, 40, 50 WPM)** students and computer institutes. 

It replicates the real exam environment with a 10-second pre-exam countdown, split-screen reference/typing layout, automated word-alignment evaluation (Full & Half mistakes), Excel-like Statement grids, E-Mail clients, and a full-featured Admin & Student Analytics Dashboard.

## 🚀 Key Features

| Feature | Description |
|---|---|
| 👨‍🎓 **Student Portal & Auth** | Secure registration/login via Email or 10-digit Mobile, 30-day persistent sessions, and live district detection. |
| ⌨️ **7 Practice & Exam Modules** | Custom `.docx` upload, UTM Practice, GCC-TBC Pre-Exam, Business Letter, Excel Statement, E-Mail, and Study Material. |
| 📊 **Excel-Style Statement Grid** | Interactive spreadsheet interface with cell navigation, formatting ribbon (Bold, Italic, Merge, Borders, Comma style), and `.xlsx` import. |
| 📧 **Realistic E-Mail Client** | Dedicated `To`, `Cc`, `Bcc`, `Subject`, `Message`, and modal file attachment selector (`📎`). |
| 🧮 **Smart GCC-TBC Evaluation** | Dynamic programming word-alignment algorithm detecting **Full Mistakes**, **Half Mistakes** (Case/Punctuation), **Omitted**, and **Untyped** words. |
| 🔍 **Visual Answer Comparison** | Color-coded side-by-side comparison screen highlighting Correct (Green), Half Mistake (Orange), Wrong/Omitted (Red), and Extra words. |
| 📈 **Student Profile & Graph** | Custom HTML5 Canvas performance graph tracking Net WPM & Accuracy over the last 10 tests, plus full test history. |
| 🛠️ **Complete Admin Panel** | Manage students, upload `.docx`/`.xlsx`/`.pdf` content, customize marking rules/penalties, view login logs, and export results to CSV. |
| 🔒 **Enterprise Security** | CSRF token protection, `password_hash` encryption, Admin brute-force IP lockout, and `.htaccess` upload execution protection. |

## ⌨️ Available Typing Modules

1. **📄 Upload Word File (`.docx`)** – Upload any custom Word document and practice with configurable WPM, duration, and Backspace/Delete locks.
2. **⌨️ UTM Practice** – Practice institute-curated passages categorized by difficulty (`Easy`, `Medium`, `Hard`).
3. **⏱️ Pre-Exam Mode** – Real GCC-TBC exam simulation with a 10-second countdown, strict key restrictions, and 40-mark scoring.
4. **✉️ Business Letter** – Rich-text editor with a Word-like interactive ruler (First-line, Left, Right indents), alignment tools, and table insertion.
5. **📊 Statement (Spreadsheet)** – Replicates MS Excel formatting and grid structure directly in the browser.
6. **📧 E-Mail Typing** – Practice formal e-mail drafting along with attachment selection.
7. **📚 Study Material PDF & Objective MCQ** – Read uploaded PDF notes and practice interactive Multiple-Choice Questions with explanations.

## 🌐 Supported Languages & Speeds

<div align="center">

<img src="https://img.shields.io/badge/English-Supported-00D9FF?style=for-the-badge" />
<img src="https://img.shields.io/badge/Marathi%20(मराठी)-Supported-00D9FF?style=for-the-badge" />
<img src="https://img.shields.io/badge/Hindi%20(हिंदी)-Supported-00D9FF?style=for-the-badge" />

<br/>

<img src="https://img.shields.io/badge/30%20WPM-Supported-16A34A?style=flat-square" />
<img src="https://img.shields.io/badge/40%20WPM-Supported-16A34A?style=flat-square" />
<img src="https://img.shields.io/badge/50%20WPM-Supported-16A34A?style=flat-square" />

</div>

## 🛠️ Technology Stack

<div align="center">

| Layer | Technologies Used |
|---|---|
| **Backend** | PHP 8+ (`mysqli`, `ZipArchive`, `DOMDocument`, `finfo`) |
| **Database** | MySQL / MariaDB (`utf8mb4` full Unicode support for Marathi & Hindi) |
| **Frontend** | HTML5, CSS3 (Responsive Grid/Flexbox for Desktop, Tablet & Mobile), Vanilla JavaScript |
| **Libraries** | PDF.js (Client-side PDF text extraction), HTML5 Canvas (Performance Charts) |
| **Server** | Apache / XAMPP |

</div>

## 📂 Project Structure

```text
Universal_Typing_master/
├── index.php          # Main Student Portal, Landing Page, Exam Engine & Result Evaluation
├── admin.php          # Admin Dashboard (Content, Students, Exam Rules, CSV Export, Logs)
├── profile.php        # Student Profile, Password Reset, Test History & Performance Graph
├── utm_common.php     # Core Engine: DB Schema Auto-Updater, .docx/.xlsx Parsers & Diff Evaluator
├── install.php        # One-click Database & Default Admin Setup Script
├── style.css          # Complete Responsive Styling & Exam UI Layouts
├── logo.jpg           # Institute / Project Logo
└── uploads/           # Auto-created secure storage for Passages, Letters, PDFs & Study Material
