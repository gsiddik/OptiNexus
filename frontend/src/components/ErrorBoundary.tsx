import { Component, type ErrorInfo, type ReactNode } from 'react';
import { isChunkLoadError } from '../errors/chunkErrors';
import { newRequestId, shortRef } from '../api/client';
import { translate } from '../i18n';

interface Props {
  /** `app`: the whole screen (no shell to keep); `route`: only the page area, the menu and top bar stay usable. */
  scope: 'app' | 'route';
  /** Changing this clears a caught error (the route boundary resets when the address changes). */
  resetKey?: string;
  children: ReactNode;
}

interface State {
  error: Error | null;
  reference: string | null;
  resetKey: string | undefined;
}

/** A failure while rendering shows a message, a reload action and a reference instead of a blank screen. */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null, reference: null, resetKey: this.props.resetKey };

  static getDerivedStateFromError(error: Error): Partial<State> {
    return { error, reference: shortRef(newRequestId()) };
  }

  static getDerivedStateFromProps(props: Props, state: State): Partial<State> | null {
    if (props.resetKey !== state.resetKey) return { error: null, reference: null, resetKey: props.resetKey };
    return null;
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // Technical detail goes to the console for whoever debugs it; the screen shows only the reference.
    console.error(`[portal] render error (${this.state.reference ?? 'no-ref'})`, error, info.componentStack);
  }

  private retry = () => this.setState({ error: null, reference: null });

  render() {
    const { error, reference } = this.state;
    if (!error) return this.props.children;

    const chunk = isChunkLoadError(error);
    const app = this.props.scope === 'app';
    const title = translate(chunk ? 'boundary.chunkTitle' : app ? 'boundary.appTitle' : 'boundary.title');
    const body = translate(chunk ? 'boundary.chunkBody' : app ? 'boundary.appBody' : 'boundary.body');

    const content = (
      <div className="state-card card" role="alert" data-testid={chunk ? 'chunk-error' : 'render-error'}>
        {app ? <h1>{title}</h1> : <h2>{title}</h2>}
        <p className="muted">{body}</p>
        {reference && !chunk && <p className="muted"><code>{translate('boundary.reference', { id: reference })}</code></p>}
        <div className="modal-actions state-actions">
          {!chunk && !app && <button type="button" className="btn btn-ghost" onClick={this.retry}>{translate('common.retry')}</button>}
          {/* A plain link, not router navigation: the router itself may be what failed. */}
          <a className="btn btn-secondary" href="/">{translate('boundary.home')}</a>
          <button type="button" className="btn btn-primary" onClick={() => window.location.reload()}>{translate('common.reload')}</button>
        </div>
      </div>
    );
    return app ? <div className="login-page">{content}</div> : content;
  }
}
