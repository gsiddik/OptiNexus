import { HelpInput } from '../../components/FieldControls';
import { useId } from 'react';
import { useI18n, type MessageKey } from '../../i18n';
import { isInsecureUrl } from './oidcForm';

interface UrlListEditorProps {
  label: string;
  hint?: string;
  values: string[];
  onChange: (values: string[]) => void;
  /** One problem per row (a message key), shown under the row. */
  problems: (MessageKey | null | undefined)[];
  /** Messages from the server for the same rows, shown in addition. */
  serverRows?: Record<number, string[]>;
  listProblem?: MessageKey;
  min: number;
  max: number;
  testId: string;
}

/** A list of exact URLs: one input per row, add/remove, a message under the row that is wrong, and a warning for plain http. */
export function UrlListEditor({ label, hint, values, onChange, problems, serverRows, listProblem, min, max, testId }: UrlListEditorProps) {
  const { t } = useI18n();
  const base = useId();
  const set = (index: number, value: string) => onChange(values.map((v, i) => (i === index ? value : v)));

  return (
    <fieldset className="url-fieldset" data-testid={testId}>
      <legend>{label}</legend>
      {hint && <span className="field-hint">{hint}</span>}
      <div className="url-list">
        {values.map((value, index) => {
          const problem = problems[index];
          const server = serverRows?.[index];
          const inputId = `${base}-${index}`;
          return (
            <div className="url-row" key={index}>
              <div className="url-row-body">
                <HelpInput help="Enter an exact allowed URL including scheme, host, and path. Wildcards and fragments are not allowed."
                  id={inputId}
                  type="url"
                  inputMode="url"
                  autoComplete="off"
                  spellCheck={false}
                  aria-label={`${label} - ${t('oidc.form.urlN', { n: index + 1 })}`}
                  aria-invalid={problem || server ? true : undefined}
                  aria-describedby={problem || server ? `${inputId}-err` : undefined}
                  className={problem || server ? 'invalid' : undefined}
                  placeholder="https://app.example.com/auth/callback"
                  value={value}
                  onChange={(e) => set(index, e.target.value)}
                />
                {(problem || server) && (
                  <span className="field-error" id={`${inputId}-err`}>{problem ? t(problem) : server?.join(' ')}</span>
                )}
                {!problem && value.trim() !== '' && isInsecureUrl(value) && <span className="field-warning">{t('oidc.warn.insecure')}</span>}
              </div>
              <button
                type="button"
                className="btn btn-ghost"
                disabled={values.length <= min}
                aria-label={t('oidc.form.removeUrl', { n: index + 1 })}
                onClick={() => onChange(values.filter((_, i) => i !== index))}
              >
                ×
              </button>
            </div>
          );
        })}
      </div>
      {listProblem && <span className="field-error">{t(listProblem, { max })}</span>}
      <div>
        <button type="button" className="btn btn-secondary btn-sm" disabled={values.length >= max} onClick={() => onChange([...values, ''])}>
          + {t('oidc.form.addUrl')}
        </button>
      </div>
    </fieldset>
  );
}
