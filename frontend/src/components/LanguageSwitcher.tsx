import { LOCALES, useI18n } from '../i18n';

/** English / Bahasa Indonesia. A short pair of buttons that fits the top bar and the sign-in cards alike. */
export function LanguageSwitcher() {
  const { locale, setLocale, t } = useI18n();
  return (
    <div className="lang-switcher" role="group" aria-label={t('lang.label')}>
      {LOCALES.map((code) => (
        <button
          key={code}
          type="button"
          className={`lang-btn${locale === code ? ' active' : ''}`}
          aria-pressed={locale === code}
          lang={code}
          title={t(code === 'en' ? 'lang.en' : 'lang.id')}
          onClick={() => setLocale(code)}
        >
          {code.toUpperCase()}
        </button>
      ))}
    </div>
  );
}
