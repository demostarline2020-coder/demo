# AI SVG Vectorizer (Full Stack)

Modern AI-powered SVG vectorizer web app with React + Vite + Tailwind frontend and Node.js + Express backend.

## Features
- Drag-and-drop upload (PNG/JPG/WebP)
- Background removal
- Grayscale + threshold + sharpening pipeline
- SVG vectorization with Potrace
- Instant SVG preview + download
- Dark premium UI
- Robust validation and error handling

## Folder Structure
```
backend/
  src/
    middleware/
    routes/
    utils/
frontend/
  src/
    components/
    pages/
    services/
    utils/
```

## Install
```bash
npm install
npm run install:all
```

## Backend Setup
```bash
cp backend/.env.example backend/.env
npm run dev --prefix backend
```

## Frontend Setup
```bash
cp frontend/.env.example frontend/.env
npm run dev --prefix frontend
```

## Run Both Together
```bash
npm run dev
```

## API
### `POST /api/vectorize`
FormData key: `image` (PNG/JPG/WebP, max 10MB)

Response:
```json
{ "svg": "<svg ...>...</svg>" }
```

## Full Dependencies
### Backend
- express
- cors
- dotenv
- multer
- sharp
- @imgly/background-removal-node
- potrace
- nodemon (dev)

### Frontend
- react
- react-dom
- axios
- tailwindcss
- postcss
- autoprefixer
- vite
- @vitejs/plugin-react

### Root
- concurrently

## Deploy
- Deploy backend to Render/Railway/Fly.
- Set env vars (`PORT`, `FRONTEND_ORIGIN`).
- Deploy frontend to Vercel/Netlify.
- Set `VITE_API_BASE_URL` to deployed backend URL.
- Ensure CORS `FRONTEND_ORIGIN` matches frontend domain.
