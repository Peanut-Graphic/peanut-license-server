import { expect } from 'vitest';
import { toHaveNoViolations } from 'jest-axe';

// Real jest-axe matcher (previously a stub that always failed because jest-axe
// wasn't installed — which, combined with the missing @testing-library/dom peer
// dep, made all 21 frontend suites fail to load).
expect.extend(toHaveNoViolations);
