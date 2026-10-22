import { Button, TextControl } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const config = window.iconLibraryAdmin;

function RenameModal( { items, closeModal, onDone } ) {
	const item = items[ 0 ];
	const [ label, setLabel ] = useState( item.label );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const request = useRef( null );
	useEffect( () => () => request.current?.abort(), [] );

	const save = async () => {
		if ( ! label.trim() ) {
			setError( __( 'An icon label is required.', 'aculect-icon-library' ) );
			return;
		}
		setIsSaving( true );
		setError( '' );
		request.current = new AbortController();
		try {
			await apiFetch( {
				path: `${ config.customPath }/${ encodeURIComponent( item.name ) }`,
				method: 'PATCH',
				data: { label: label.trim() },
				signal: request.current.signal,
			} );
			onDone();
			closeModal();
		} catch ( requestError ) {
			setError( requestError?.message || __( 'The icon could not be renamed.', 'aculect-icon-library' ) );
			setIsSaving( false );
		}
	};

	return (
		<>
			<TextControl label={ __( 'Label', 'aculect-icon-library' ) } value={ label } onChange={ setLabel } autoFocus __nextHasNoMarginBottom />
			{ error && <p className="icon-library-dataviews-error" role="alert">{ error }</p> }
			<div className="icon-library-dataviews-modal-actions">
				<Button variant="tertiary" onClick={ closeModal } disabled={ isSaving }>{ __( 'Cancel', 'aculect-icon-library' ) }</Button>
				<Button variant="primary" onClick={ save } isBusy={ isSaving } disabled={ isSaving }>{ __( 'Save', 'aculect-icon-library' ) }</Button>
			</div>
		</>
	);
}

function DeleteModal( { items, closeModal, onDone, onPartialDelete } ) {
	const item = items[ 0 ];
	const [ isDeleting, setIsDeleting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const request = useRef( null );
	useEffect( () => () => request.current?.abort(), [] );

	const remove = async () => {
		setIsDeleting( true );
		setError( '' );
		request.current = new AbortController();
		try {
			await apiFetch( { path: `${ config.customPath }/${ encodeURIComponent( item.name ) }`, method: 'DELETE', signal: request.current.signal } );
			onDone();
			closeModal();
		} catch ( requestError ) {
			if ( requestError?.data?.removed === true ) {
				onDone();
				onPartialDelete( requestError.message || __( 'The icon was removed, but its stored artwork could not be cleaned up. Ask your site administrator to check uploads permissions.', 'aculect-icon-library' ) );
				closeModal();
				return;
			}
			setError( requestError?.message || __( 'The icon could not be deleted.', 'aculect-icon-library' ) );
			setIsDeleting( false );
		}
	};

	return (
		<>
			<p>{ __( 'This permanently deletes the icon and its SVG file. Existing blocks using it will no longer render.', 'aculect-icon-library' ) }</p>
			{ error && <p className="icon-library-dataviews-error" role="alert">{ error }</p> }
			<div className="icon-library-dataviews-modal-actions">
				<Button variant="tertiary" onClick={ closeModal } disabled={ isDeleting }>{ __( 'Cancel', 'aculect-icon-library' ) }</Button>
				<Button variant="primary" isDestructive onClick={ remove } isBusy={ isDeleting } disabled={ isDeleting }>{ __( 'Delete', 'aculect-icon-library' ) }</Button>
			</div>
		</>
	);
}

function CustomIconsDataView() {
	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 20,
		search: '',
		filters: [],
		fields: [ 'preview', 'label', 'iconName' ],
		sort: { field: 'label', direction: 'asc' },
		layout: {
			styles: {
				preview: { width: '96px', minWidth: '96px', maxWidth: '96px' },
				iconName: { width: '42%', minWidth: '220px' },
			},
		},
	} );
	const [ data, setData ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ listError, setListError ] = useState( '' );
	const [ actionWarning, setActionWarning ] = useState( '' );
	const [ hideData, setHideData ] = useState( false );
	const [ refresh, setRefresh ] = useState( 0 );
	const lastSuccessfulQuery = useRef( '' );

	useEffect( () => {
		let active = true;
		const controller = new AbortController();
		setIsLoading( true );
		setListError( '' );
		const query = new URLSearchParams( {
			page: String( view.page || 1 ),
			per_page: String( view.perPage || 20 ),
			search: view.search || '',
			orderby: view.sort?.field === 'name' ? 'name' : 'label',
			order: view.sort?.direction || 'asc',
		} );
		const queryKey = query.toString();
		apiFetch( { path: `${ config.customPath }?${ queryKey }`, signal: controller.signal } )
			.then( ( response ) => {
				if ( active ) {
						const lastPage = Math.max( 1, Math.ceil( response.total / ( view.perPage || 20 ) ) );
						if ( view.page > lastPage ) {
							setView( ( currentView ) => ( { ...currentView, page: lastPage } ) );
							return;
						}
					setData( response.items );
					setTotal( response.total );
					setHideData( false );
					lastSuccessfulQuery.current = queryKey;
				}
			} )
			.catch( ( error ) => {
				if ( active ) {
					if ( lastSuccessfulQuery.current !== queryKey ) {
						setData( [] );
						setTotal( 0 );
						setHideData( true );
					}
					setListError( error?.message || __( 'Uploaded icons could not be loaded.', 'aculect-icon-library' ) );
				}
			} )
			.finally( () => active && setIsLoading( false ) );
		return () => { active = false; controller.abort(); };
	}, [ view.page, view.perPage, view.search, view.sort?.field, view.sort?.direction, refresh ] );

	const fields = useMemo( () => [
		{
			id: 'preview',
			label: __( 'Preview', 'aculect-icon-library' ),
			// The REST response contains only SVG markup accepted by the server-side SvgSanitizer.
			render: ( { item } ) => <span className="icon-library-dataviews-preview" aria-hidden="true" dangerouslySetInnerHTML={ { __html: item.svg } } />,
		},
		{ id: 'label', label: __( 'Label', 'aculect-icon-library' ), enableGlobalSearch: true, enableSorting: true },
		{
			id: 'iconName',
			label: __( 'Icon name', 'aculect-icon-library' ),
			enableGlobalSearch: true,
			render: ( { item } ) => <code className="icon-library-dataviews-name" title={ item.iconName }>{ item.iconName }</code>,
		},
	], [] );

	const changed = useCallback( () => {
		setActionWarning( '' );
		setRefresh( ( value ) => value + 1 );
	}, [] );
	const retryList = () => {
		const heading = document.querySelector( '.icon-library-custom-heading' );
		if ( heading ) {
			heading.setAttribute( 'tabindex', '-1' );
			heading.focus( { preventScroll: true } );
		}
		setRefresh( ( value ) => value + 1 );
	};
	const actions = useMemo( () => [
		{
			id: 'rename',
			label: __( 'Rename', 'aculect-icon-library' ),
			modalHeader: __( 'Rename icon', 'aculect-icon-library' ),
			isPrimary: true,
			supportsBulk: false,
			RenderModal: ( props ) => <RenameModal { ...props } onDone={ changed } />,
		},
		{
			id: 'delete',
			label: __( 'Delete', 'aculect-icon-library' ),
			modalHeader: __( 'Delete icon', 'aculect-icon-library' ),
			isPrimary: true,
			isDestructive: true,
			supportsBulk: false,
			RenderModal: ( props ) => <DeleteModal { ...props } onDone={ changed } onPartialDelete={ setActionWarning } />,
		},
	], [ changed ] );

	return (
		<>
			{ actionWarning && <p className="icon-library-dataviews-list-error" role="alert">{ actionWarning }</p> }
			{ listError && <div className="icon-library-dataviews-list-error" role="alert">
				<p>{ listError }</p>
				<Button variant="secondary" onClick={ retryList }>{ __( 'Retry', 'aculect-icon-library' ) }</Button>
			</div> }
			{ ! hideData && <DataViews
			data={ data }
			fields={ fields }
			view={ view }
			onChangeView={ setView }
			actions={ actions }
			isLoading={ isLoading }
			paginationInfo={ { totalItems: total, totalPages: Math.max( 1, Math.ceil( total / ( view.perPage || 20 ) ) ) } }
			search
			searchLabel={ __( 'Search icons', 'aculect-icon-library' ) }
			defaultLayouts={ { table: {} } }
			/> }
		</>
	);
}

let mountedRoot = null;
let mountedElement = null;

window.iconLibraryUnmountCustomDataViews = () => {
	if ( mountedRoot ) {
		mountedRoot.unmount();
		mountedRoot = null;
	}
	if ( mountedElement ) {
		delete mountedElement.dataset.mounted;
		mountedElement = null;
	}
};

window.iconLibraryMountCustomDataViews = () => {
	const root = document.getElementById( 'icon-library-custom-dataviews' );
	if ( root && root !== mountedElement ) {
		window.iconLibraryUnmountCustomDataViews();
		root.dataset.mounted = 'true';
		mountedElement = root;
		mountedRoot = wp.element.createRoot( root );
		mountedRoot.render( <CustomIconsDataView /> );
	}
};

window.iconLibraryMountCustomDataViews();
