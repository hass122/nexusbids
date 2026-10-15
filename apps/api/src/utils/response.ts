import { Response } from 'express';
import { ApiResponse } from '@nexusbids/shared';

export function sendSuccess<T>(
  res: Response,
  data?: T,
  message?: string,
  statusCode = 200,
  meta?: ApiResponse['meta']
): void {
  const payload: ApiResponse<T> = {
    success: true,
    data,
    message,
    meta,
  };
  res.status(statusCode).json(payload);
}

export function sendError(
  res: Response,
  error: string,
  statusCode = 400,
  details?: any
): void {
  const payload: ApiResponse = {
    success: false,
    error,
    data: details,
  };
  res.status(statusCode).json(payload);
}

export function sendPaginated<T>(
  res: Response,
  data: T[],
  total: number,
  page: number,
  limit: number,
  statusCode = 200
): void {
  const totalPages = Math.ceil(total / limit);
  const payload: ApiResponse<T[]> = {
    success: true,
    data,
    meta: {
      total,
      page,
      limit,
      totalPages,
      hasNext: page < totalPages,
      hasPrev: page > 1,
    },
  };
  res.status(statusCode).json(payload);
}
