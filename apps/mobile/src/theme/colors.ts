export const Colors = {
  // 1. Core Backgrounds & Canvas (Soft Royal Purple Palette)
  background: '#0B1020',       // App background: Main screen background (#0B1020)
  canvasSubtle: '#070B16',     // Deepest navy canvas
  surface: '#141B2D',          // Card / surface: Cards, panels, input surfaces (#141B2D)
  surfaceLight: '#1B243B',     // Elevated panel
  surfaceElevated: '#1B243B',  // Elevated cards & sheets
  surfaceHighlight: '#222C46', // Selected state background
  card: '#141B2D',             // Dark navy card (#141B2D)
  header: '#0B1020',           // Deep navy header (#0B1020)

  // Borders
  border: '#29334C',           // Surface border: Subtle dividers and outlines (#29334C)
  borderSubtle: 'rgba(41, 51, 76, 0.6)', // Hairline divider
  borderLight: '#354263',      // Interactive border
  borderFocus: '#7868C8',      // Purple focus ring

  // Brand Primaries & Accents (Primary purple: #7868C8, Light lavender: #A69AE8)
  primary: '#7868C8',          // Primary purple: Primary buttons and selected controls (#7868C8)
  primaryPressed: '#8B7BD8',   // Pressed/active button feedback
  primaryDark: '#6253B0',      // Deep purple
  primaryLight: '#A69AE8',     // Light lavender: Small highlights, selected icons, accent text (#A69AE8)
  primaryGlow: 'rgba(120, 104, 200, 0.20)',

  // Typography
  text: '#F8FAFC',             // Main text: Headings, prices, important information (#F8FAFC)
  textPrimary: '#F8FAFC',      // Explicit token alias
  textWhite: '#FFFFFF',        // Pure white
  textBody: '#C5C9D8',         // Body text: Normal descriptions and readable content (#C5C9D8)
  textSecondary: '#A1AABD',    // Secondary text: Timestamps, helper labels, muted metadata (#A1AABD)
  textMuted: '#A1AABD',        // Supporting text and labels (#A1AABD)
  textDim: '#7B8499',          // Timestamps / tertiary metadata

  // Status & Financial Indicators
  success: '#10B981',          // Success states only (#10B981)
  successLight: 'rgba(16, 185, 129, 0.14)',
  warning: '#F59E0B',          // Amber: Ending soon / Reserve pending
  warningLight: 'rgba(245, 158, 11, 0.12)',
  danger: '#EF4444',           // Error/destructive states only (#EF4444)
  error: '#EF4444',            // Explicit alias
  dangerLight: 'rgba(239, 68, 68, 0.14)',
  info: '#3B82F6',             // Info blue
  accent: '#A69AE8',           // Lavender accent (#A69AE8)
  accentLight: 'rgba(166, 154, 232, 0.14)',
  gold: '#F59E0B',             // Verified gold
  badgeBg: 'rgba(120, 104, 200, 0.16)', // Soft purple badge bg
};
