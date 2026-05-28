import express from 'express';
import { upload } from '../middleware/upload.js';
import { processAndVectorize } from '../utils/vectorizePipeline.js';

const router = express.Router();

router.post('/', upload.single('image'), async (req, res) => {
  try {
    if (!req.file) return res.status(400).json({ error: 'Please upload an image file.' });

    const svg = await processAndVectorize(req.file.path);
    return res.json({ svg });
  } catch (error) {
    const message = error?.message || 'Failed to vectorize image.';
    if (message.includes('Only PNG')) return res.status(400).json({ error: message });
    return res.status(500).json({ error: message });
  }
});

export default router;
