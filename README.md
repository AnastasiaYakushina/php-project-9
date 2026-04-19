### Hexlet tests and linter status:
[![Actions Status](https://github.com/AnastasiaYakushina/php-project-9/actions/workflows/hexlet-check.yml/badge.svg)](https://github.com/AnastasiaYakushina/php-project-9/actions)

Ссылка на проект:
https://php-project-9-8xoz.onrender.com

### Описание
**Анализатор страниц** - веб-приложение на PHP, которое позволяет проверять указанные сайты на SEO-пригодность: приложение скачивает страницу, анализирует её статус-код и извлекает важные мета-теги.

### Системные требования
-  PHP 8.2+
-  Composer
-  PostgreSQL

### Установка и запуск

1. **Клонирование репозитория**  
   `git clone git@github.com:AnastasiaYakushina/php-project-9.git`  
   *Копирует исходный код проекта в локальную папку.*

2. **Установка зависимостей**  
   `make install`  
   *Загружает и устанавливает необходимые библиотеки и пакеты.*

3. **Настройка переменных окружения**  
   `echo "DATABASE_URL=postgresql://user:password@localhost:5432/dbname" > .env`  
   *Создает файл `.env` с параметрами подключения к базе данных PostgreSQL.*

4. **Инициализация базы данных**  
   `psql -d dbname -f database.sql`  
   *Создает структуру таблиц в БД на основе предоставленного SQL-файла.*

5. **Запуск приложения**  
   `make start`  
   *Запускает локальный сервер для работы с приложением.*


